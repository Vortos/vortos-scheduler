<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Vortos\Scheduler\Audit\SchedulerAuditProjector;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Engine\Exception\FireDispatchException;
use Vortos\Scheduler\Lease\Exception\LeaseNotOwnedException;
use Vortos\Scheduler\Lease\Exception\LeaseRenewExpiredException;
use Vortos\Scheduler\Lease\Lease;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Lease\LeaseToken;
use Vortos\Scheduler\Lease\LeaseHeartbeatGuard;
use Vortos\Scheduler\Observability\DeadManDetector;
use Vortos\Scheduler\Observability\SchedulerMetricsPort;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\CadenceCursor;
use Vortos\Scheduler\Store\ScheduleCursorStoreInterface;

/**
 * Leader-elected distributed scheduler daemon.
 *
 * Each shard `s` races to acquire lease key `scheduler:leader:{s}` via LeasePort.
 * Schedules are assigned to shards by `abs(crc32(scheduleId)) % shardCount` —
 * the same formula used in DueScan::belongsToShard(). Both must stay in sync.
 *
 * CORRECTNESS INVARIANT (from SPEC_SCHEDULER.md §2):
 *   Exactly-once effect is guaranteed by the idempotent fire
 *   (UNIQUE(tenant_id, schedule_id, slot) in the fire-ledger), NOT by the lease.
 *   A split-brain double-tick, a lease backend hiccup, or two daemons briefly
 *   believing they are leader all collapse to a single enqueued command.
 *
 * Per-tenant fairness cap (tenantMaxConcurrentFires):
 *   Limits fires dispatched per tenant per tick so one tenant's burst can't
 *   starve others within a shard. 0 = unlimited.
 *
 * API:
 *   run()     — production loop; blocks until stop() is called.
 *   stop()    — signal clean shutdown (safe from SIGTERM handler).
 *   runOnce() — one full cycle without sleeping; returns true if any shard was held.
 *               Used by tests and scheduler:run-now (S9).
 *
 * Static helpers (used by scheduler:doctor, S9):
 *   leaseKeyForShard(int)         → "scheduler:leader:{n}"
 *   shardIndexFor(ScheduleId,int) → abs(crc32(id)) % shardCount
 */
final class SchedulerDaemon
{
    private const LEASE_KEY_FORMAT       = 'scheduler:leader:%d';
    private const MIN_STANDBY_SLEEP      = 5;
    private const MAX_STANDBY_SLEEP      = 10;
    private const MAX_BACKOFF_SEC        = 300;
    private const SIGNAL_POLL_SEC        = 1;
    private const STANDBY_JITTER_WINDOW  = (self::MAX_STANDBY_SLEEP - self::MIN_STANDBY_SLEEP) * 1000;

    private bool $running            = false;
    private bool $tokensInitialized  = false;

    /** @var array<int, LeaseToken> */
    private array $shardTokens = [];

    /** @var array<int, Lease|null> */
    private array $shardLeases = [];

    private readonly LeaseHeartbeatGuard $heartbeatGuard;
    private readonly int $nodeJitterMs;

    public function __construct(
        private readonly LeasePort                 $leasePort,
        private readonly ScheduleResolver          $scheduleResolver,
        private readonly ScheduleCursorStoreInterface $cursorStore,
        private readonly DueScan                   $dueScan,
        private readonly FireDispatcherPort        $fireDispatcher,
        private readonly ClockPort                 $clock,
        private readonly LoggerInterface           $logger,
        private readonly int $shardCount               = 1,
        private readonly int $leaseTtlSec              = 30,
        private readonly int $maxIdleSec               = 60,
        private readonly int $tenantMaxConcurrentFires  = 0,
        private readonly ?SchedulerMetricsPort     $metrics   = null,
        private readonly ?SchedulerAuditProjector  $audit     = null,
        private readonly ?DeadManDetector          $deadMan   = null,
    ) {
        if ($shardCount < 1) {
            throw new \InvalidArgumentException(
                \sprintf('SchedulerDaemon: shardCount must be >= 1, got %d.', $shardCount),
            );
        }
        if ($leaseTtlSec < 5) {
            throw new \InvalidArgumentException(
                \sprintf('SchedulerDaemon: leaseTtlSec must be >= 5, got %d.', $leaseTtlSec),
            );
        }
        if ($maxIdleSec < 1) {
            throw new \InvalidArgumentException(
                \sprintf('SchedulerDaemon: maxIdleSec must be >= 1, got %d.', $maxIdleSec),
            );
        }
        if ($tenantMaxConcurrentFires < 0) {
            throw new \InvalidArgumentException(
                \sprintf('SchedulerDaemon: tenantMaxConcurrentFires must be >= 0, got %d.', $tenantMaxConcurrentFires),
            );
        }

        $this->heartbeatGuard = new LeaseHeartbeatGuard();
        $this->nodeJitterMs   = $this->computeNodeStandbyJitterMs();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Production loop. Blocks until stop() is called.
     * Releases all held shard leases before returning.
     */
    public function run(): void
    {
        $this->running = true;
        $this->initShardTokensOnce();
        $backoff = 1;

        while ($this->running) {
            $this->dispatchSignals();

            try {
                $heldShards = $this->acquireOrRenewAllShards();

                if (empty($heldShards)) {
                    // Standby: another node leads all shards; wait with node-seeded jitter.
                    // Node jitter is deterministic per (hostname:pid) to avoid synchronized
                    // lease-acquisition storms when all replicas start simultaneously.
                    $standbySec = self::MIN_STANDBY_SLEEP + intdiv($this->nodeJitterMs, 1000);
                    $this->sleepInterruptible($standbySec);
                    continue;
                }

                $now     = $this->clock->now();
                $nextDue = null;

                foreach ($heldShards as $shardIndex) {
                    $shardNextDue = $this->tickShard($shardIndex, $now);
                    if ($shardNextDue !== null && ($nextDue === null || $shardNextDue < $nextDue)) {
                        $nextDue = $shardNextDue;
                    }
                }

                $backoff = 1; // reset after successful tick

                $maxWake = $now->modify("+{$this->maxIdleSec} seconds");
                $wakeAt  = ($nextDue !== null && $nextDue < $maxWake) ? $nextDue : $maxWake;
                $this->sleepUntil($wakeAt);

            } catch (\Throwable $e) {
                $this->logger->error('Scheduler daemon tick failed', [
                    'error'   => $e->getMessage(),
                    'backoff' => $backoff,
                ]);
                $this->releaseAllLeases();
                $this->sleepInterruptible($backoff);
                $backoff = \min($backoff * 2, self::MAX_BACKOFF_SEC);
            }
        }

        $this->releaseAllLeases();
    }

    /**
     * Signals the daemon to stop after the current tick finishes.
     * Safe to call from a SIGTERM/SIGINT signal handler.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Performs exactly one full cycle: acquire/renew all shard leases, then
     * tick each held shard. Does NOT sleep. Returns true if any shard was held.
     *
     * Used by tests and by scheduler:run-now (S9) to trigger a synthetic fire.
     */
    public function runOnce(): bool
    {
        $this->initShardTokensOnce();

        $heldShards = $this->acquireOrRenewAllShards();

        if (empty($heldShards)) {
            return false;
        }

        $now = $this->clock->now();

        foreach ($heldShards as $shardIndex) {
            $this->tickShard($shardIndex, $now);
        }

        return true;
    }

    /**
     * Canonical shard lease key. Used by scheduler:doctor (S9) to verify
     * per-shard lease driver presence.
     */
    public static function leaseKeyForShard(int $shardIndex): string
    {
        return \sprintf(self::LEASE_KEY_FORMAT, $shardIndex);
    }

    /**
     * Deterministic shard assignment: abs(crc32(scheduleId)) % shardCount.
     *
     * abs() guards against signed-int overflow on 64-bit PHP where crc32()
     * can return a negative value. This formula must stay identical to
     * DueScan::belongsToShard() — they are intentionally copies, not shared,
     * so each can evolve independently if the engine ever changes.
     */
    public static function shardIndexFor(ScheduleId $id, int $shardCount): int
    {
        if ($shardCount <= 1) {
            return 0;
        }

        return \abs(\crc32($id->toString())) % $shardCount;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: shard lease lifecycle
    // ─────────────────────────────────────────────────────────────────────────

    private function initShardTokensOnce(): void
    {
        if ($this->tokensInitialized) {
            return;
        }

        for ($s = 0; $s < $this->shardCount; $s++) {
            $this->shardTokens[$s] = LeaseToken::generate();
            $this->shardLeases[$s] = null;
        }

        $this->tokensInitialized = true;
    }

    /** @return list<int> */
    private function acquireOrRenewAllShards(): array
    {
        $held = [];

        for ($s = 0; $s < $this->shardCount; $s++) {
            if ($this->acquireOrRenewShard($s) !== null) {
                $held[] = $s;
            }
        }

        return $held;
    }

    private function acquireOrRenewShard(int $shardIndex): ?Lease
    {
        $key   = self::leaseKeyForShard($shardIndex);
        $token = $this->shardTokens[$shardIndex];
        $held  = $this->shardLeases[$shardIndex] ?? null;
        $now   = $this->clock->now();

        // Renew if we hold the lease and are past the first 1/3 of TTL
        if ($held !== null && !$held->isExpired($now)) {
            $renewThreshold = $held->acquiredAt->modify('+' . (int) ($this->leaseTtlSec / 3) . ' seconds');

            if ($now >= $renewThreshold) {
                try {
                    $renewed                        = $this->leasePort->renew($held, $this->leaseTtlSec);
                    $this->shardLeases[$shardIndex] = $renewed;
                    $this->heartbeatGuard->recordHeartbeat($shardIndex, $this->clock->now());

                    $this->logger->debug('Scheduler: shard lease renewed', [
                        'shard'      => $shardIndex,
                        'expires_at' => $renewed->expiresAt->format(\DateTimeInterface::ATOM),
                    ]);

                    return $renewed;
                } catch (LeaseNotOwnedException | LeaseRenewExpiredException $e) {
                    // Lease was stolen or expired — fall through to fresh acquire
                    $this->logger->info('Scheduler: shard lease lost during renew, will re-acquire', [
                        'shard' => $shardIndex,
                        'error' => $e->getMessage(),
                    ]);
                    $this->shardLeases[$shardIndex] = null;
                }
            } else {
                return $held; // Still healthy; no renew needed yet
            }
        }

        // Fresh acquire (or re-acquire after expiry/loss)
        $acquired                       = $this->leasePort->acquire($key, $token, $this->leaseTtlSec);
        $this->shardLeases[$shardIndex] = $acquired;

        if ($acquired !== null) {
            $this->logger->info('Scheduler: shard lease acquired', [
                'shard'      => $shardIndex,
                'expires_at' => $acquired->expiresAt->format(\DateTimeInterface::ATOM),
            ]);
            $this->heartbeatGuard->clear($shardIndex);
            $this->metrics?->recordLeaderAcquired($shardIndex);
            $this->audit?->onLeaderAcquired($shardIndex);
        } elseif ($held === null) {
            // We tried to acquire but another node holds it
            $this->metrics?->recordLeaseContention($shardIndex);
        }

        return $acquired;
    }

    private function releaseShard(int $shardIndex): void
    {
        $held = $this->shardLeases[$shardIndex] ?? null;

        if ($held === null) {
            return;
        }

        try {
            $this->leasePort->release($held);
            $this->logger->info('Scheduler: shard lease released', ['shard' => $shardIndex]);
            $this->metrics?->recordLeaderLost($shardIndex);
            $this->audit?->onLeaderLost($shardIndex);
        } catch (\Throwable $e) {
            $this->logger->warning('Scheduler: failed to release shard lease (will expire via TTL)', [
                'shard' => $shardIndex,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->shardLeases[$shardIndex] = null;
        }
    }

    private function releaseAllLeases(): void
    {
        for ($s = 0; $s < $this->shardCount; $s++) {
            $this->releaseShard($s);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: tick
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One scan+dispatch cycle for a single shard.
     * Returns the earliest next-due instant across shard schedules, or null.
     */
    private function tickShard(int $shardIndex, DateTimeImmutable $now): ?DateTimeImmutable
    {
        // ScheduleResolver merges static (compile-time) + dynamic (DB) schedules.
        // Throws ScheduleNameCollisionException on static ↔ dynamic name/ID collision;
        // the outer run() catch handles this with exponential backoff + ERROR logging.
        $allSchedules = [...$this->scheduleResolver->activeView()];

        // Filter to this shard and build lookup map
        /** @var array<string, \Vortos\Scheduler\Schedule\Schedule> $shardSchedules */
        $shardSchedules = [];
        $scheduleIds    = [];

        foreach ($allSchedules as $schedule) {
            if (self::shardIndexFor($schedule->id, $this->shardCount) !== $shardIndex) {
                continue;
            }
            $shardSchedules[$schedule->id->toString()] = $schedule;
            $scheduleIds[] = $schedule->id;
        }

        if (empty($scheduleIds)) {
            return null;
        }

        // Single bulk query for all cadence cursors in this shard. Missing entries mean the
        // schedule has never been scanned — DueScan anchors those to `now` (no retroactive
        // catch-up). Cursors are read from the dedicated cursor store, NOT the execution log,
        // so manual run-now fires never perturb automatic cadence.
        $cursors           = $this->cursorStore->findCursors($scheduleIds, null);
        $cursorAtBySchedule = [];
        foreach ($cursors as $scheduleIdStr => $cursor) {
            $cursorAtBySchedule[$scheduleIdStr] = $cursor->cursorAt;
        }

        // DueScan receives pre-filtered schedules; shard params omitted (list already filtered)
        $scanResult = $this->dueScan->compute(
            array_values($shardSchedules),
            $cursorAtBySchedule,
            $now,
        );

        foreach ($scanResult->dropped as $drop) {
            $this->logger->warning('Scheduler: slot dropped beyond catch-up horizon', [
                'shard'       => $shardIndex,
                'schedule_id' => $drop->scheduleId->toString(),
                'tenant_id'   => $drop->tenantId,
                'dropped_at'  => $drop->droppedAt->format(\DateTimeInterface::ATOM),
                'reason'      => $drop->reason,
            ]);
            $this->audit?->onSlotDropped($drop);
        }

        // DueScan returns fires ordered scheduledFor ASC; dispatch in that order
        /** @var array<string, int> $tenantFireCount */
        $tenantFireCount = [];

        // Cadence-cursor bookkeeping (advanced after the dispatch loop). A schedule's cursor may
        // only advance past slots that were actually settled this tick — a throttled, deferred,
        // circuit-open or failed slot must be re-evaluated next tick, so it "blocks" its schedule
        // and the cursor stops at the last contiguous settled slot (or does not move).
        /** @var array<string, true> $blockedSchedules */
        $blockedSchedules = [];
        /** @var array<string, \DateTimeImmutable> $lastSettledFor */
        $lastSettledFor = [];
        $dispatchAborted = false;

        foreach ($scanResult->fires as $fire) {
            // Per-tenant fairness cap (null tenant maps to empty-string bucket)
            $bucket = $fire->tenantId ?? '';

            if ($this->tenantMaxConcurrentFires > 0) {
                $alreadyDispatched = $tenantFireCount[$bucket] ?? 0;
                if ($alreadyDispatched >= $this->tenantMaxConcurrentFires) {
                    $this->logger->info('Scheduler: fire throttled by tenant fairness cap', [
                        'shard'       => $shardIndex,
                        'tenant_id'   => $fire->tenantId,
                        'schedule_id' => $fire->scheduleId->toString(),
                        'slot'        => $fire->slot,
                        'cap'         => $this->tenantMaxConcurrentFires,
                    ]);
                    $this->metrics?->recordFairnessThrottle($fire->tenantId);
                    $blockedSchedules[$fire->scheduleId->toString()] = true;
                    continue;
                }
            }

            $schedule = $shardSchedules[$fire->scheduleId->toString()] ?? null;
            if ($schedule === null) {
                $this->logger->error('Scheduler: no schedule object found for fire (schedule may have been deleted)', [
                    'shard'       => $shardIndex,
                    'schedule_id' => $fire->scheduleId->toString(),
                    'slot'        => $fire->slot,
                ]);
                continue;
            }

            // Heartbeat health check (E2): if renewal has been silent for > 90% of TTL,
            // voluntarily skip dispatch this tick. The lease will expire naturally and
            // another node will take over. Idempotency still holds if we do dispatch.
            if (!$this->heartbeatGuard->isHealthy($shardIndex, $now, $this->leaseTtlSec)) {
                $this->logger->warning('Scheduler: heartbeat guard unhealthy — skipping dispatch for shard', [
                    'shard' => $shardIndex,
                ]);
                // Abort before advancing any cursor: we may be about to lose the lease, and another
                // node must see the un-advanced cursors to pick up the un-dispatched slots.
                $dispatchAborted = true;
                break;
            }

            // Renew lease mid-batch if we've passed the half-TTL mark
            $this->renewBeforeDispatch($shardIndex);

            try {
                $dispatchResult = $this->fireDispatcher->dispatch($fire, $schedule);

                $this->logger->info('Scheduler: fire result', [
                    'shard'       => $shardIndex,
                    'schedule_id' => $fire->scheduleId->toString(),
                    'slot'        => $fire->slot,
                    'result'      => $dispatchResult->name,
                ]);

                $this->metrics?->recordFireResult(
                    $dispatchResult,
                    $fire->scheduleId->toString(),
                    $fire->tenantId,
                );

                if ($dispatchResult === FireDispatchResult::Dispatched) {
                    $tenantFireCount[$bucket] = ($tenantFireCount[$bucket] ?? 0) + 1;

                    $lagMs = (int) (($now->getTimestamp() - $fire->scheduledFor->getTimestamp()) * 1000);
                    $this->metrics?->recordDispatchLag($lagMs, $fire->scheduleId->toString(), $fire->tenantId);
                    $this->audit?->onFireDispatched($fire, max(0, $lagMs), $schedule->jitter !== null);
                } elseif ($dispatchResult === FireDispatchResult::SkippedOverlap) {
                    $this->audit?->onFireSkippedOverlap($fire, '', 'dispatched');
                }

                // Settled outcomes let the cursor advance past this slot; Deferred (jitter window
                // not yet elapsed) and CircuitOpen retry the same slot next tick, so they block.
                $sid = $fire->scheduleId->toString();
                $settled = $dispatchResult === FireDispatchResult::Dispatched
                    || $dispatchResult === FireDispatchResult::AlreadyDispatched
                    || $dispatchResult === FireDispatchResult::SkippedOverlap;

                if ($settled) {
                    if (!isset($blockedSchedules[$sid])) {
                        $lastSettledFor[$sid] = $fire->scheduledFor;
                    }
                } else {
                    $blockedSchedules[$sid] = true;
                }
            } catch (FireDispatchException $e) {
                // Per-fire exception — log and continue; systemic failures propagate out.
                // A failed slot is not settled: block its schedule so the cursor does not advance
                // past it and it is retried next tick.
                $blockedSchedules[$fire->scheduleId->toString()] = true;
                $this->logger->error('Scheduler: fire dispatch failed (continuing to next fire)', [
                    'shard'       => $shardIndex,
                    'schedule_id' => $fire->scheduleId->toString(),
                    'slot'        => $fire->slot,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Advance cadence cursors for every schedule that was fully settled this tick. Skipped
        // entirely when dispatch was aborted (heartbeat), so a taking-over node sees un-advanced
        // cursors. This is what advances the anchor even when a policy fires nothing (SkipMissed).
        if (!$dispatchAborted) {
            $this->advanceCursors(
                $shardSchedules,
                $scanResult->newCursors,
                $cursors,
                $blockedSchedules,
                $lastSettledFor,
                $shardIndex,
            );
        }

        $this->metrics?->recordActiveSchedules(count($shardSchedules));

        // Dead-man check: run once per tick after dispatch
        if ($this->deadMan !== null) {
            $this->deadMan->check(array_values($shardSchedules));
        }

        // Compute earliest next-due across all shard schedules for precise sleep
        $nextDue = null;
        foreach ($shardSchedules as $schedule) {
            $next = $schedule->trigger->nextRunAfter($now);
            if ($next !== null && ($nextDue === null || $next < $nextDue)) {
                $nextDue = $next;
            }
        }

        return $nextDue;
    }

    /**
     * CAS-advance each schedule's cadence cursor to the settled high-water mark for this tick.
     *
     * A schedule with no due fires (nothing enumerated, or a policy that collapsed the batch such
     * as SkipMissed) advances to its DueScan-computed cursor — this is what keeps the anchor moving
     * and prevents the never-advancing deadlock. A schedule that had an unsettled slot advances only
     * to its last contiguous settled slot (or not at all), so unsettled slots retry next tick.
     *
     * @param array<string, \Vortos\Scheduler\Schedule\Schedule> $shardSchedules keyed by scheduleId
     * @param array<string, \DateTimeImmutable>                  $newCursors     DueScan result
     * @param array<string, CadenceCursor>                       $currentCursors as read this tick
     * @param array<string, true>                                $blockedSchedules
     * @param array<string, \DateTimeImmutable>                  $lastSettledFor
     */
    private function advanceCursors(
        array $shardSchedules,
        array $newCursors,
        array $currentCursors,
        array $blockedSchedules,
        array $lastSettledFor,
        int   $shardIndex,
    ): void {
        foreach ($shardSchedules as $sid => $schedule) {
            if (isset($blockedSchedules[$sid])) {
                $target = $lastSettledFor[$sid] ?? null;
            } else {
                $target = $newCursors[$sid] ?? null;
            }

            if ($target === null) {
                continue;
            }

            $expectedVersion = isset($currentCursors[$sid]) ? $currentCursors[$sid]->version : 0;

            $advanced = $this->cursorStore->advance(
                $schedule->id,
                $schedule->tenantId,
                $target,
                $expectedVersion,
            );

            if (!$advanced) {
                $this->logger->info('Scheduler: cadence cursor advance lost race (another node moved it first)', [
                    'shard'       => $shardIndex,
                    'schedule_id' => $sid,
                ]);
            }
        }
    }

    private function renewBeforeDispatch(int $shardIndex): void
    {
        $held = $this->shardLeases[$shardIndex] ?? null;

        if ($held === null) {
            return;
        }

        // Renew when we've consumed more than half the TTL since last acquire/renew
        $halfTtl = (int) ($this->leaseTtlSec / 2);
        $renewAt = $held->acquiredAt->modify("+{$halfTtl} seconds");

        if ($this->clock->now() >= $renewAt) {
            try {
                $this->shardLeases[$shardIndex] = $this->leasePort->renew($held, $this->leaseTtlSec);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Scheduler: mid-dispatch lease renewal failed — idempotency anchor still safe',
                    ['shard' => $shardIndex, 'error' => $e->getMessage()],
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private: sleep
    // ─────────────────────────────────────────────────────────────────────────

    private function sleepUntil(DateTimeImmutable $until): void
    {
        while ($this->running) {
            $this->dispatchSignals();

            $remainSec = $until->getTimestamp() - $this->clock->now()->getTimestamp();

            if ($remainSec <= 0) {
                break;
            }

            $sleepSec = (int) \min($remainSec, self::SIGNAL_POLL_SEC);
            \usleep($sleepSec * 1_000_000);
        }
    }

    private function sleepInterruptible(int $seconds): void
    {
        $this->sleepUntil($this->clock->now()->modify("+{$seconds} seconds"));
    }

    private function dispatchSignals(): void
    {
        if (\function_exists('pcntl_signal_dispatch')) {
            \pcntl_signal_dispatch();
        }
    }

    /**
     * Returns a deterministic jitter offset in milliseconds for standby sleep.
     *
     * Using crc32(hostname:pid) rather than random_int() makes the jitter stable
     * across ticks, so replicas that start simultaneously spread out predictably
     * instead of re-colliding on every standby cycle. abs() guards against the
     * signed-int overflow crc32() can produce on 64-bit PHP.
     */
    private function computeNodeStandbyJitterMs(): int
    {
        $seed = \abs(\crc32(\gethostname() . ':' . \getmypid()));

        return $seed % (self::STANDBY_JITTER_WINDOW + 1);
    }
}
