<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Observability;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

/**
 * Declares all scheduler metric instruments for the framework metric registry (S8).
 *
 * Tagged {@see MetricDefinitionProviderInterface::TAG} so MetricDefinitionsCompilerPass
 * merges these into the global registry at container compile time.
 *
 * ## Cardinality guidance
 *
 *  - `result`, `policy`, `direction` — bounded enum values (safe).
 *  - `shard` — bounded by scheduler.shard_count (default 1, rarely > 8).
 *  - `schedule_id` — bounded by the number of active schedules (typically < 1 000 in
 *    enterprise). NOT user-level cardinality. Matches FeatureFlags' `flag` label pattern.
 *  - `tenant_id` — bounded by the number of tenants per deployment. Should be omitted
 *    (set to 'system') for installations with > 10 000 tenants — see operator runbook.
 */
final class SchedulerMetricDefinitions implements MetricDefinitionProviderInterface
{
    private const LAG_BUCKETS_MS       = [0, 100, 250, 500, 1_000, 5_000, 15_000, 30_000, 60_000, 300_000];
    private const PRUNE_DURATION_BUCKETS_SEC = [0.1, 0.5, 1, 5, 15, 30, 60, 120, 240];

    public function definitions(): array
    {
        return [
            MetricDefinition::counter(
                'vortos_scheduler_fires_total',
                'Total scheduler fire attempts, labelled by result.',
                ['result', 'schedule_id', 'tenant_id'],
            ),
            MetricDefinition::counter(
                'vortos_scheduler_misfires_total',
                'Total scheduler misfire catch-up fires, labelled by policy applied.',
                ['policy', 'schedule_id', 'tenant_id'],
            ),
            MetricDefinition::histogram(
                'vortos_scheduler_dispatch_lag_ms',
                'Scheduler dispatch lag in milliseconds (now − scheduledFor) per fire.',
                ['schedule_id', 'tenant_id'],
                self::LAG_BUCKETS_MS,
            ),
            MetricDefinition::counter(
                'vortos_scheduler_lease_contention_total',
                'Total scheduler lease acquisition failures (another node already leads the shard).',
                ['shard'],
            ),
            MetricDefinition::counter(
                'vortos_scheduler_leader_changes_total',
                'Total scheduler leader transitions per shard.',
                ['shard', 'direction'],
            ),
            MetricDefinition::gauge(
                'vortos_scheduler_active_schedules',
                'Current number of active (non-paused, non-disabled) schedules visible to the daemon.',
                [],
            ),
            MetricDefinition::counter(
                'vortos_scheduler_fairness_throttled_total',
                'Total fires throttled by the per-tenant concurrency cap.',
                ['tenant_id'],
            ),
            MetricDefinition::counter(
                'vortos_scheduler_audit_failures_total',
                'Total audit append failures (non-fatal; counted for visibility).',
                ['event_type'],
            ),
            MetricDefinition::counter(
                'vortos_scheduler_consume_results_total',
                'Total fire-queue rows executed through the CQRS CommandBus (S12), labelled by result.',
                ['result', 'schedule_id', 'tenant_id'],
            ),
            MetricDefinition::counter(
                'vortos_scheduler_runs_pruned_total',
                'Total fire-ledger rows deleted by pruneOldRuns(), auto or manual.',
                ['tenant_id'],
            ),
            MetricDefinition::counter(
                'vortos_scheduler_fire_queue_pruned_total',
                'Total terminal (dispatched/failed) fire-queue rows deleted by FireQueuePruner.',
                [],
            ),
            MetricDefinition::histogram(
                'vortos_scheduler_prune_duration_seconds',
                'Wall-clock duration of one full prune sweep, labelled by trigger (auto|manual).',
                ['trigger'],
                self::PRUNE_DURATION_BUCKETS_SEC,
            ),
        ];
    }
}
