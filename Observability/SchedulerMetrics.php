<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Observability;

use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;

/**
 * Emits scheduler metrics through the framework {@see MetricsInterface} (S8).
 *
 * Constructor accepts `?MetricsInterface` — when null (no metrics backend wired) every
 * method early-returns at zero cost. When a backend is present, all calls are wrapped in
 * try/catch so a broken metrics sink never affects the scheduler's dispatch cycle.
 *
 * Metric names are declared in {@see SchedulerMetricDefinitions}.
 */
final class SchedulerMetrics implements SchedulerMetricsPort
{
    public function __construct(private readonly ?MetricsInterface $metrics = null) {}

    public function recordFireResult(FireDispatchResult $result, string $scheduleId, ?string $tenantId): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_fires_total',
                [
                    'result'      => $this->fireResultLabel($result),
                    'schedule_id' => $scheduleId,
                    'tenant_id'   => $tenantId ?? 'system',
                ],
            )->increment();
        } catch (\Throwable) {
        }
    }

    public function recordMisfire(MisfirePolicy $policy, string $scheduleId, ?string $tenantId): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_misfires_total',
                [
                    'policy'      => $this->misfireLabel($policy),
                    'schedule_id' => $scheduleId,
                    'tenant_id'   => $tenantId ?? 'system',
                ],
            )->increment();
        } catch (\Throwable) {
        }
    }

    public function recordDispatchLag(int $lagMs, string $scheduleId, ?string $tenantId): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->histogram(
                'vortos_scheduler_dispatch_lag_ms',
                [
                    'schedule_id' => $scheduleId,
                    'tenant_id'   => $tenantId ?? 'system',
                ],
            )->observe((float) max(0, $lagMs));
        } catch (\Throwable) {
        }
    }

    public function recordLeaseContention(int $shardIndex): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_lease_contention_total',
                ['shard' => (string) $shardIndex],
            )->increment();
        } catch (\Throwable) {
        }
    }

    public function recordLeaderAcquired(int $shardIndex): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_leader_changes_total',
                ['shard' => (string) $shardIndex, 'direction' => 'acquired'],
            )->increment();
        } catch (\Throwable) {
        }
    }

    public function recordLeaderLost(int $shardIndex): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_leader_changes_total',
                ['shard' => (string) $shardIndex, 'direction' => 'lost'],
            )->increment();
        } catch (\Throwable) {
        }
    }

    public function recordActiveSchedules(int $count): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->gauge('vortos_scheduler_active_schedules')->set((float) $count);
        } catch (\Throwable) {
        }
    }

    public function recordFairnessThrottle(?string $tenantId): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_fairness_throttled_total',
                ['tenant_id' => $tenantId ?? 'system'],
            )->increment();
        } catch (\Throwable) {
        }
    }

    public function recordAuditFailure(string $eventType): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_audit_failures_total',
                ['event_type' => $eventType],
            )->increment();
        } catch (\Throwable) {
        }
    }

    public function recordConsumeResult(bool $success, string $scheduleId, ?string $tenantId): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_consume_results_total',
                [
                    'result'      => $success ? 'success' : 'failure',
                    'schedule_id' => $scheduleId,
                    'tenant_id'   => $tenantId ?? 'system',
                ],
            )->increment();
        } catch (\Throwable) {
        }
    }

    public function recordFireRequeued(string $reason, string $scheduleId, ?string $tenantId): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_fire_requeued_total',
                [
                    'reason'      => $reason,
                    'schedule_id' => $scheduleId,
                    'tenant_id'   => $tenantId ?? 'system',
                ],
            )->increment();
        } catch (\Throwable) {
        }
    }

    public function recordFireDeadLettered(string $reason, string $scheduleId, ?string $tenantId): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_fire_dead_lettered_total',
                [
                    'reason'      => $reason,
                    'schedule_id' => $scheduleId,
                    'tenant_id'   => $tenantId ?? 'system',
                ],
            )->increment();
        } catch (\Throwable) {
        }
    }

    public function recordRunsPruned(int $count, ?string $tenantId): void
    {
        if ($this->metrics === null || $count === 0) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_runs_pruned_total',
                ['tenant_id' => $tenantId ?? 'system'],
            )->increment((float) $count);
        } catch (\Throwable) {
        }
    }

    public function recordFireQueuePruned(int $count): void
    {
        if ($this->metrics === null || $count === 0) {
            return;
        }

        try {
            $this->metrics->counter(
                'vortos_scheduler_fire_queue_pruned_total',
                [],
            )->increment((float) $count);
        } catch (\Throwable) {
        }
    }

    public function recordPruneDuration(float $seconds, string $trigger): void
    {
        if ($this->metrics === null) {
            return;
        }

        try {
            $this->metrics->histogram(
                'vortos_scheduler_prune_duration_seconds',
                ['trigger' => $trigger],
            )->observe(max(0.0, $seconds));
        } catch (\Throwable) {
        }
    }

    private function fireResultLabel(FireDispatchResult $result): string
    {
        return match ($result) {
            FireDispatchResult::Dispatched        => 'dispatched',
            FireDispatchResult::AlreadyDispatched => 'already_dispatched',
            FireDispatchResult::SkippedOverlap    => 'skipped_overlap',
            FireDispatchResult::Deferred          => 'deferred',
        };
    }

    private function misfireLabel(MisfirePolicy $policy): string
    {
        return $policy->key();
    }
}
