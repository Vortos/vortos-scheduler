<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\Observability\Audit\AuditHashChain;
use Vortos\Scheduler\Engine\DroppedSlotRecord;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * Projects scheduler events into the tamper-evident hash-chained audit ledger (S8).
 *
 * ## Safety contract for fire / leader events
 *
 * These are called directly from the daemon tick and MUST NOT throw — a broken audit
 * backend must never kill a scheduler dispatch cycle. All exceptions are caught, logged,
 * and counted (via `scheduler_audit_failures_total`, if metrics are wired).
 *
 * ## Safety contract for mutation events
 *
 * `onScheduleCreated`, `onScheduleUpdated`, etc. are called by operator commands and
 * service layer. Exceptions propagate — the operator sees the error and retries.
 */
final class SchedulerAuditProjector
{
    public function __construct(
        private readonly SchedulerAuditRepositoryInterface            $repository,
        private readonly string                                        $hmacKey,
        private readonly string                                        $env,
        private readonly AuditHashChain                               $chain = new AuditHashChain(),
        private readonly LoggerInterface                              $logger = new NullLogger(),
        private readonly ?SchedulerAuditCheckpointProjector           $checkpointProjector = null,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Fire events (daemon-facing — must never throw)
    // ─────────────────────────────────────────────────────────────────────────

    public function onFireDispatched(ScheduledFire $fire, int $lagMs, bool $jitterApplied): void
    {
        $this->tryAppend(
            SchedulerAuditEvent::FireDispatched,
            'system',
            $fire->tenantId,
            $fire->scheduleId->toString(),
            $fire->slot,
            null,
            [
                'lag_ms'          => $lagMs,
                'attempt'         => $fire->attempt,
                'jitter_applied'  => $jitterApplied,
            ],
        );
    }

    public function onFireSkippedOverlap(ScheduledFire $fire, string $priorRunId, string $priorRunState): void
    {
        $this->tryAppend(
            SchedulerAuditEvent::FireSkippedOverlap,
            'system',
            $fire->tenantId,
            $fire->scheduleId->toString(),
            $fire->slot,
            null,
            [
                'prior_run_id'    => $priorRunId,
                'prior_run_state' => $priorRunState,
            ],
        );
    }

    public function onFireMisfired(ScheduledFire $fire, MisfirePolicy $policy, int $slotsFired, int $slotsDropped): void
    {
        $this->tryAppend(
            SchedulerAuditEvent::FireMisfired,
            'system',
            $fire->tenantId,
            $fire->scheduleId->toString(),
            $fire->slot,
            null,
            [
                'policy_applied'  => $policy->key(),
                'slots_fired'     => $slotsFired,
                'slots_dropped'   => $slotsDropped,
            ],
        );
    }

    public function onSlotDropped(DroppedSlotRecord $drop): void
    {
        $this->tryAppend(
            SchedulerAuditEvent::FireDropped,
            'system',
            $drop->tenantId,
            $drop->scheduleId->toString(),
            null,
            null,
            [
                'reason'      => $drop->reason,
                'dropped_at'  => $drop->droppedAt->format(DateTimeInterface::ATOM),
            ],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Leader election events (daemon-facing — must never throw)
    // ─────────────────────────────────────────────────────────────────────────

    public function onLeaderAcquired(int $shardIndex): void
    {
        $this->tryAppend(
            SchedulerAuditEvent::LeaderAcquired,
            'system',
            null,
            null,
            null,
            $shardIndex,
            ['node_id' => gethostname() ?: 'unknown'],
        );
    }

    public function onLeaderLost(int $shardIndex): void
    {
        $this->tryAppend(
            SchedulerAuditEvent::LeaderLost,
            'system',
            null,
            null,
            null,
            $shardIndex,
            ['node_id' => gethostname() ?: 'unknown'],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Schedule mutation events (operator-facing — exceptions propagate)
    // ─────────────────────────────────────────────────────────────────────────

    public function onScheduleCreated(Schedule $schedule, string $actorId): void
    {
        $this->append(
            SchedulerAuditEvent::ScheduleCreated,
            $actorId,
            $schedule->tenantId,
            $schedule->id->toString(),
            null,
            null,
            [
                'name'            => $schedule->name,
                'trigger_desc'    => $schedule->trigger->describe(),
                'misfire_policy'  => $schedule->misfire->key(),
                'overlap_policy'  => $schedule->overlap->value,
                'source'          => $schedule->source->value,
            ],
        );
    }

    public function onScheduleUpdated(Schedule $schedule, string $actorId, ?string $reason): void
    {
        $this->append(
            SchedulerAuditEvent::ScheduleUpdated,
            $actorId,
            $schedule->tenantId,
            $schedule->id->toString(),
            null,
            null,
            [
                'name'           => $schedule->name,
                'trigger_desc'   => $schedule->trigger->describe(),
                'misfire_policy' => $schedule->misfire->key(),
                'overlap_policy' => $schedule->overlap->value,
                'reason'         => $reason,
            ],
        );
    }

    public function onSchedulePaused(Schedule $schedule, string $actorId, ?string $reason): void
    {
        $this->append(
            SchedulerAuditEvent::SchedulePaused,
            $actorId,
            $schedule->tenantId,
            $schedule->id->toString(),
            null,
            null,
            ['name' => $schedule->name, 'reason' => $reason],
        );
    }

    public function onScheduleResumed(Schedule $schedule, string $actorId): void
    {
        $this->append(
            SchedulerAuditEvent::ScheduleResumed,
            $actorId,
            $schedule->tenantId,
            $schedule->id->toString(),
            null,
            null,
            ['name' => $schedule->name],
        );
    }

    public function onScheduleDeleted(Schedule $schedule, string $actorId, ?string $reason): void
    {
        $this->append(
            SchedulerAuditEvent::ScheduleDeleted,
            $actorId,
            $schedule->tenantId,
            $schedule->id->toString(),
            null,
            null,
            ['name' => $schedule->name, 'reason' => $reason],
        );
    }

    public function onScheduleApproved(Schedule $schedule, string $approverId, string $originalActorId): void
    {
        $this->append(
            SchedulerAuditEvent::ScheduleApproved,
            $approverId,
            $schedule->tenantId,
            $schedule->id->toString(),
            null,
            null,
            [
                'name'              => $schedule->name,
                'approver_id'       => $approverId,
                'original_actor_id' => $originalActorId,
            ],
        );
    }

    public function onManualFire(Schedule $schedule, string $actorId, string $slot, ?string $reason): void
    {
        $this->tryAppend(
            SchedulerAuditEvent::FireManual,
            $actorId,
            $schedule->tenantId,
            $schedule->id->toString(),
            $slot,
            null,
            ['name' => $schedule->name, 'reason' => $reason],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Run retention / auto-prune
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Records one prune sweep. Uses tryAppend() deliberately: by the time this is
     * called the delete has already happened and cannot be undone, so failing the
     * command over an audit-write hiccup would misreport a successful prune as a
     * failure. See SCHEDULER_AUTO_PRUNE_IMPL_PLAN.md item 5.
     */
    public function onRunsPruned(
        string $actorId,
        ?string $tenantId,
        int $deletedCount,
        DateTimeImmutable $cutoff,
        bool $truncated,
        bool $resolved = true,
    ): void {
        $this->tryAppend(
            SchedulerAuditEvent::RunsPruned,
            $actorId,
            $tenantId,
            null,
            null,
            null,
            [
                'deleted_count' => $deletedCount,
                'cutoff'        => $cutoff->format(DateTimeInterface::ATOM),
                'truncated'     => $truncated,
                // false only for the manual --before bypass, which skips the
                // per-tenant/global resolver entirely (SchedulePruneCommand).
                'resolved'      => $resolved,
            ],
        );
    }

    /**
     * Operator mutation — exceptions propagate (same contract as onSchedulePaused/etc).
     */
    public function onRetentionOverrideSet(string $tenantId, int $retentionDays, string $actorId, ?string $reason): void
    {
        $this->append(
            SchedulerAuditEvent::RetentionOverrideSet,
            $actorId,
            $tenantId,
            null,
            null,
            null,
            ['retention_days' => $retentionDays, 'reason' => $reason],
        );
    }

    public function onRetentionOverrideRemoved(string $tenantId, string $actorId): void
    {
        $this->append(
            SchedulerAuditEvent::RetentionOverrideRemoved,
            $actorId,
            $tenantId,
            null,
            null,
            null,
            [],
        );
    }

        // ─────────────────────────────────────────────────────────────────────────
    // Private: core append logic
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Append an audit entry. Exceptions propagate (mutation-event path).
     *
     * @param array<string, mixed> $data
     */
    private function append(
        SchedulerAuditEvent $event,
        string $actorId,
        ?string $tenantId,
        ?string $scheduleId,
        ?string $slot,
        ?int $shardIndex,
        array $data,
    ): void {
        $chainKey   = sprintf('scheduler:%s:%s', $tenantId ?? 'system', $this->env);
        $occurredAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $entryId    = $this->generateUuid();
        $hmacKey    = $this->hmacKey;

        $entry = $this->repository->appendNext(
            $chainKey,
            function (int $sequence, string $prevHash) use (
                $entryId, $event, $actorId, $tenantId, $scheduleId, $slot,
                $shardIndex, $occurredAt, $data, $chainKey, $hmacKey
            ): SchedulerAuditEntry {
                $hashable = [
                    'entry_id'    => $entryId,
                    'sequence'    => $sequence,
                    'event_type'  => $event->value,
                    'actor_id'    => $actorId,
                    'tenant_id'   => $tenantId,
                    'schedule_id' => $scheduleId,
                    'slot'        => $slot,
                    'shard_index' => $shardIndex,
                    'occurred_at' => $occurredAt,
                    'data'        => $data,
                    'chain_key'   => $chainKey,
                ];

                $contentHash    = $this->chain->contentHash($hashable, $prevHash);
                $signingMessage = $this->chain->signingMessage($entryId, $sequence, $contentHash, $prevHash);
                $signature      = $this->chain->sign($signingMessage, $hmacKey);

                return new SchedulerAuditEntry(
                    entryId:     $entryId,
                    sequence:    $sequence,
                    eventType:   $event->value,
                    actorId:     $actorId,
                    tenantId:    $tenantId,
                    scheduleId:  $scheduleId,
                    slot:        $slot,
                    shardIndex:  $shardIndex,
                    occurredAt:  $occurredAt,
                    data:        $data,
                    chainKey:    $chainKey,
                    prevHash:    $prevHash,
                    contentHash: $contentHash,
                    signature:   $signature,
                );
            },
        );

        $this->checkpointProjector?->maybeCheckpoint(
            $entry->chainKey,
            $entry->sequence,
            $entry->contentHash,
        );
    }

    /**
     * Variant of append() that catches all exceptions — for fire/leader events called
     * from the daemon tick where a broken audit backend must not stall dispatch.
     *
     * @param array<string, mixed> $data
     */
    private function tryAppend(
        SchedulerAuditEvent $event,
        string $actorId,
        ?string $tenantId,
        ?string $scheduleId,
        ?string $slot,
        ?int $shardIndex,
        array $data,
    ): void {
        try {
            $this->append($event, $actorId, $tenantId, $scheduleId, $slot, $shardIndex, $data);
        } catch (\Throwable $e) {
            $this->logger->error('Scheduler audit append failed', [
                'event_type' => $event->value,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
