<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Observability;

use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;

/**
 * Cardinality-guard decorator for {@see SchedulerMetricsPort}.
 *
 * Prometheus TSDB cardinality explosion: with N schedules, emitting `schedule_id`
 * as a label creates N×counters per metric family. At 5000+ schedules this OOMs
 * Prometheus and causes slow scrapes.
 *
 * This decorator:
 *   - Tracks distinct `schedule_id` values seen since process start.
 *   - Values within `$maxDistinctSchedules` (default 200) pass through unchanged.
 *   - Values beyond the limit are replaced with `__overflow__` and a
 *     `vortos_scheduler_metric_overflow_total` counter is incremented so ops can
 *     detect when the guard is active.
 *
 * Ops note: if `scheduler_metric_overflow_total > 0`, the Prometheus cardinality
 * guard is active. Per-schedule detail remains available in the audit log.
 */
final class CardinalityGuardedSchedulerMetrics implements SchedulerMetricsPort
{
    public const OVERFLOW_SENTINEL = '__overflow__';
    public const OVERFLOW_METRIC   = 'vortos_scheduler_metric_overflow_total';

    /** @var array<string, true> schedule_id → seen */
    private array $seenScheduleIds = [];

    public function __construct(
        private readonly SchedulerMetricsPort $inner,
        private readonly ?MetricsInterface    $metrics = null,
        private readonly int                  $maxDistinctSchedules = 200,
    ) {}

    public function recordFireResult(FireDispatchResult $result, string $scheduleId, ?string $tenantId): void
    {
        $this->inner->recordFireResult($result, $this->sanitize($scheduleId), $tenantId);
    }

    public function recordMisfire(MisfirePolicy $policy, string $scheduleId, ?string $tenantId): void
    {
        $this->inner->recordMisfire($policy, $this->sanitize($scheduleId), $tenantId);
    }

    public function recordDispatchLag(int $lagMs, string $scheduleId, ?string $tenantId): void
    {
        $this->inner->recordDispatchLag($lagMs, $this->sanitize($scheduleId), $tenantId);
    }

    public function recordLeaseContention(int $shardIndex): void
    {
        $this->inner->recordLeaseContention($shardIndex);
    }

    public function recordLeaderAcquired(int $shardIndex): void
    {
        $this->inner->recordLeaderAcquired($shardIndex);
    }

    public function recordLeaderLost(int $shardIndex): void
    {
        $this->inner->recordLeaderLost($shardIndex);
    }

    public function recordActiveSchedules(int $count): void
    {
        $this->inner->recordActiveSchedules($count);
    }

    public function recordFairnessThrottle(?string $tenantId): void
    {
        $this->inner->recordFairnessThrottle($tenantId);
    }

    public function recordAuditFailure(string $eventType): void
    {
        $this->inner->recordAuditFailure($eventType);
    }

    private function sanitize(string $scheduleId): string
    {
        if (isset($this->seenScheduleIds[$scheduleId])) {
            return $scheduleId;
        }

        if (count($this->seenScheduleIds) < $this->maxDistinctSchedules) {
            $this->seenScheduleIds[$scheduleId] = true;
            return $scheduleId;
        }

        try {
            $this->metrics?->counter(self::OVERFLOW_METRIC)->increment();
        } catch (\Throwable) {
        }

        return self::OVERFLOW_SENTINEL;
    }
}
