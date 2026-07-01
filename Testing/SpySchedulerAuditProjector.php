<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use Vortos\Scheduler\Audit\SchedulerAuditEvent;
use Vortos\Scheduler\Engine\DroppedSlotRecord;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * Spy for {@see SchedulerAuditProjector} — captures all calls for assertion.
 *
 * Only depends on the Scheduler domain types (no I/O, no DB) so it is safe to
 * instantiate in pure unit tests.
 *
 * Usage:
 *   $spy = new SpySchedulerAuditProjector();
 *   // Inject it wherever SchedulerAuditProjector is consumed
 *   $spy->assertEventRecorded(SchedulerAuditEvent::FireDispatched);
 */
final class SpySchedulerAuditProjector
{
    /** @var list<array{event: SchedulerAuditEvent, data: array<string, mixed>}> */
    public array $recorded = [];

    public function onFireDispatched(ScheduledFire $fire, int $lagMs, bool $jitterApplied): void
    {
        $this->record(SchedulerAuditEvent::FireDispatched, [
            'fire'          => $fire,
            'lag_ms'        => $lagMs,
            'jitter_applied' => $jitterApplied,
        ]);
    }

    public function onFireSkippedOverlap(ScheduledFire $fire, string $priorRunId, string $priorRunState): void
    {
        $this->record(SchedulerAuditEvent::FireSkippedOverlap, [
            'fire'            => $fire,
            'prior_run_id'    => $priorRunId,
            'prior_run_state' => $priorRunState,
        ]);
    }

    public function onFireMisfired(ScheduledFire $fire, \Vortos\Scheduler\Schedule\Policy\MisfirePolicy $policy, int $slotsFired, int $slotsDropped): void
    {
        $this->record(SchedulerAuditEvent::FireMisfired, [
            'fire'         => $fire,
            'policy'       => $policy,
            'slots_fired'  => $slotsFired,
            'slots_dropped' => $slotsDropped,
        ]);
    }

    public function onSlotDropped(DroppedSlotRecord $drop): void
    {
        $this->record(SchedulerAuditEvent::FireDropped, ['drop' => $drop]);
    }

    public function onLeaderAcquired(int $shardIndex): void
    {
        $this->record(SchedulerAuditEvent::LeaderAcquired, ['shard_index' => $shardIndex]);
    }

    public function onLeaderLost(int $shardIndex): void
    {
        $this->record(SchedulerAuditEvent::LeaderLost, ['shard_index' => $shardIndex]);
    }

    public function onScheduleCreated(Schedule $schedule, string $actorId): void
    {
        $this->record(SchedulerAuditEvent::ScheduleCreated, ['schedule' => $schedule, 'actor_id' => $actorId]);
    }

    public function onScheduleUpdated(Schedule $schedule, string $actorId, string $reason): void
    {
        $this->record(SchedulerAuditEvent::ScheduleUpdated, ['schedule' => $schedule, 'actor_id' => $actorId, 'reason' => $reason]);
    }

    public function onSchedulePaused(Schedule $schedule, string $actorId, string $reason): void
    {
        $this->record(SchedulerAuditEvent::SchedulePaused, ['schedule' => $schedule, 'actor_id' => $actorId, 'reason' => $reason]);
    }

    public function onScheduleResumed(Schedule $schedule, string $actorId): void
    {
        $this->record(SchedulerAuditEvent::ScheduleResumed, ['schedule' => $schedule, 'actor_id' => $actorId]);
    }

    public function onScheduleDeleted(Schedule $schedule, string $actorId, string $reason): void
    {
        $this->record(SchedulerAuditEvent::ScheduleDeleted, ['schedule' => $schedule, 'actor_id' => $actorId, 'reason' => $reason]);
    }

    public function onScheduleApproved(Schedule $schedule, string $approverId, string $originalActorId): void
    {
        $this->record(SchedulerAuditEvent::ScheduleApproved, [
            'schedule'          => $schedule,
            'approver_id'       => $approverId,
            'original_actor_id' => $originalActorId,
        ]);
    }

    // ── Assertion helpers ──────────────────────────────────────────────────────

    public function assertEventRecorded(SchedulerAuditEvent $event): void
    {
        foreach ($this->recorded as $r) {
            if ($r['event'] === $event) {
                return;
            }
        }

        $recorded = array_map(fn (array $r) => $r['event']->value, $this->recorded);
        throw new \RuntimeException(sprintf(
            "Expected event '%s' to be recorded, but it was not. Recorded: [%s]",
            $event->value,
            implode(', ', $recorded),
        ));
    }

    public function assertEventNotRecorded(SchedulerAuditEvent $event): void
    {
        foreach ($this->recorded as $r) {
            if ($r['event'] === $event) {
                throw new \RuntimeException("Event '{$event->value}' was recorded but was not expected.");
            }
        }
    }

    public function assertEventCount(SchedulerAuditEvent $event, int $expected): void
    {
        $count = count(array_filter($this->recorded, fn (array $r) => $r['event'] === $event));

        if ($count !== $expected) {
            throw new \RuntimeException("Expected {$expected} '{$event->value}' events, got {$count}.");
        }
    }

    public function assertNothingRecorded(): void
    {
        if ($this->recorded !== []) {
            throw new \RuntimeException('Expected no audit events, but found: ' . count($this->recorded));
        }
    }

    /** @return array<string, mixed>|null */
    public function lastDataFor(SchedulerAuditEvent $event): ?array
    {
        foreach (array_reverse($this->recorded) as $r) {
            if ($r['event'] === $event) {
                return $r['data'];
            }
        }

        return null;
    }

    private function record(SchedulerAuditEvent $event, array $data): void
    {
        $this->recorded[] = ['event' => $event, 'data' => $data];
    }
}
