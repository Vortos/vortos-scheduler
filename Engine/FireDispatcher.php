<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Vortos\Scheduler\Engine\Exception\FireDispatchException;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Observability\SchedulerTracer;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Security\CommandSpecValidator;
use Vortos\Scheduler\Store\Exception\DuplicateSlotException;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Atomically enqueues a single scheduled fire.
 *
 * Exactly-once guarantee:
 *   1. BEGIN transaction
 *   2. insertRun() — UNIQUE(tenant_id, schedule_id, slot) prevents double-fire
 *   3. enqueuer->enqueue() — writes fire to scheduler fire queue (same connection)
 *   4. COMMIT
 *
 * If step 2 throws DuplicateSlotException (already dispatched), the transaction
 * is rolled back and FireDispatchResult::AlreadyDispatched is returned.
 *
 * Overlap control (OverlapPolicy::Skip):
 *   Before the transaction, FireDispatcher checks whether the most recent prior
 *   run for this schedule is still in-flight (state = Dispatched and within the
 *   assumed-done TTL watermark). This check is pre-transaction and non-blocking;
 *   the UNIQUE constraint still catches races between concurrent daemons.
 *
 * Jitter:
 *   If the schedule has a Jitter policy, FireDispatcher checks whether the jitter
 *   offset has elapsed before dispatching. Returns FireDispatchResult::Deferred
 *   if the jitter window has not yet closed. The daemon (S5) retries on the next
 *   tick, at which point the jitter will have elapsed for a deterministic slot.
 */
final class FireDispatcher implements FireDispatcherPort
{
    public function __construct(
        private readonly ScheduleRunStoreInterface $runStore,
        private readonly SchedulerEnqueuerPort     $enqueuer,
        private readonly Connection                $connection,
        private readonly ClockInterface            $clock,
        private readonly int                       $assumedDoneTtlSec = 3600,
        private readonly ?CommandSpecValidator     $validator = null,
        private readonly ?SchedulerTracer          $tracer = null,
    ) {}

    /**
     * Dispatch a single scheduled fire. Returns the outcome.
     *
     * @throws FireDispatchException on an unexpected write failure
     */
    public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
    {
        $now = $this->clock->now();

        // ── 0. Allowlist check (dispatch-time defence-in-depth) ───────────────
        $this->validator?->assert($schedule->command);

        // ── 1. Jitter check (pre-transaction, pure) ──────────────────────────
        if ($schedule->jitter !== null) {
            $nodeId = gethostname() ?: 'default';
            $jitterOffsetSec = $schedule->jitter->offsetSeconds($fire->slot, $nodeId);
            $effectiveFireAt = $fire->scheduledFor->modify("+{$jitterOffsetSec} seconds");

            if ($now < $effectiveFireAt) {
                return FireDispatchResult::Deferred;
            }
        }

        // ── 2. Overlap check (pre-transaction, non-blocking) ─────────────────
        if ($schedule->overlap === OverlapPolicy::Skip && $this->isOverlapping($schedule, $fire->slot, $now)) {
            return FireDispatchResult::SkippedOverlap;
        }

        $lagMs = (int) (($now->getTimestamp() - $fire->scheduledFor->getTimestamp()) * 1000);

        if ($this->tracer !== null) {
            return $this->tracer->traceDispatch(
                $fire->scheduleId->toString(),
                $fire->slot,
                $fire->scheduledFor,
                max(0, $lagMs),
                $fire->tenantId,
                fn () => $this->doAtomicDispatch($fire, $schedule, $now),
            );
        }

        return $this->doAtomicDispatch($fire, $schedule, $now);
    }

    private function doAtomicDispatch(ScheduledFire $fire, Schedule $schedule, \DateTimeImmutable $now): FireDispatchResult
    {
        // ── 3. Atomic: ledger insert + fire queue write ───────────────────────
        $runId = IdempotencyKey::fromSlotKey($fire->slot)->value;
        $run   = new ScheduleRun(
            runId:        $runId,
            scheduleId:   $fire->scheduleId,
            tenantId:     $fire->tenantId,
            slot:         $fire->slot,
            scheduledFor: $fire->scheduledFor,
            dispatchedAt: $now,
            state:        RunState::Dispatched,
            attempt:      $fire->attempt,
        );

        $this->connection->beginTransaction();
        try {
            $this->runStore->insertRun($run);
            $this->enqueuer->enqueue($fire, $schedule);
            $this->connection->commit();

            return FireDispatchResult::Dispatched;
        } catch (DuplicateSlotException) {
            $this->connection->rollBack();

            return FireDispatchResult::AlreadyDispatched;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw new FireDispatchException($fire, $e->getMessage(), $e);
        }
    }

    /**
     * Returns true if a prior run for this schedule is still in-flight AND
     * within the assumed-done TTL watermark.
     *
     * This is a heuristic guard, not a correctness guarantee — the UNIQUE
     * constraint in insertRun() remains the idempotency anchor.
     */
    private function isOverlapping(Schedule $schedule, string $currentSlot, DateTimeImmutable $now): bool
    {
        $lastSlots = $this->runStore->findLastSlots([$schedule->id], $schedule->tenantId);
        $priorSlot = $lastSlots[$schedule->id->toString()] ?? null;

        if ($priorSlot === null || $priorSlot === $currentSlot) {
            // Never fired, or current slot already dispatched (UNIQUE will handle it).
            return false;
        }

        $priorRun = $this->runStore->findRunBySlot($schedule->id, $priorSlot, $schedule->tenantId);

        if ($priorRun === null || $priorRun->isTerminal()) {
            return false;
        }

        // Prior run is still Dispatched — check the TTL watermark.
        // If dispatched_at + TTL has passed, assume crash; allow new fire through.
        $staleSince = $priorRun->dispatchedAt->modify("+{$this->assumedDoneTtlSec} seconds");

        return $staleSince > $now;
    }
}
