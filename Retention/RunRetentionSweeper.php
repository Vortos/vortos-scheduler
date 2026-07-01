<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Retention;

use Vortos\Scheduler\Audit\SchedulerAuditProjector;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Observability\SchedulerMetricsPort;
use Vortos\Scheduler\Observability\SchedulerTracer;
use Vortos\Scheduler\Store\RunRetentionOverrideStoreInterface;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Multi-tenant retention sweep: prunes every tenant at its own resolved
 * retention (per-tenant override, or the global default) in one pass.
 *
 * Shared by both the automatic path (PruneSchedulerRunsHandler, fired by the
 * daily static schedule) and the manual `scheduler:prune` CLI's default
 * (non-`--before`) mode — see SCHEDULER_AUTO_PRUNE_IMPL_PLAN.md item 10.
 *
 * Algorithm (item 3): one pruneOldRuns() call per tenant carrying a non-exempt
 * override, plus one final call for "every other tenant" at the global cutoff,
 * excluding the tenants already handled above. Tenants with a `0` (legal-hold)
 * override are skipped entirely — never pruned.
 */
final class RunRetentionSweeper
{
    public function __construct(
        private readonly ScheduleRunStoreInterface           $runStore,
        private readonly RunRetentionOverrideStoreInterface  $overrideStore,
        private readonly ClockPort                           $clock,
        private readonly SchedulerTracer                     $tracer,
        private readonly int                                 $globalRetentionDays,
        private readonly ?SchedulerAuditProjector             $audit = null,
        private readonly ?SchedulerMetricsPort                $metrics = null,
        private readonly ?FireQueuePruner                     $fireQueuePruner = null,
    ) {}

    public function sweep(string $trigger, string $actorId = 'system'): RunRetentionSweepResult
    {
        $start = microtime(true);

        /** @var RunRetentionSweepResult $result */
        $result = $this->tracer->tracePrune($trigger, fn () => $this->doSweep($actorId));

        $this->metrics?->recordPruneDuration(microtime(true) - $start, $trigger);

        return $result;
    }

    private function doSweep(string $actorId): RunRetentionSweepResult
    {
        $now       = $this->clock->now();
        $overrides = $this->overrideStore->findAll();

        $totalDeleted        = 0;
        $truncated            = false;
        $overriddenTenantIds  = [];

        foreach ($overrides as $override) {
            $overriddenTenantIds[] = $override->tenantId;

            if ($override->isExempt()) {
                continue; // legal hold — never pruned
            }

            $cutoff = $now->modify(sprintf('-%d days', $override->retentionDays));
            $result = $this->runStore->pruneOldRuns($cutoff, $override->tenantId);

            $totalDeleted += $result->deletedCount;
            $truncated     = $truncated || $result->truncated;

            $this->metrics?->recordRunsPruned($result->deletedCount, $override->tenantId);
            $this->audit?->onRunsPruned($actorId, $override->tenantId, $result->deletedCount, $cutoff, $result->truncated);
        }

        $globalCutoff = $now->modify(sprintf('-%d days', $this->globalRetentionDays));
        $globalResult = $this->runStore->pruneOldRuns($globalCutoff, null, $overriddenTenantIds);

        $totalDeleted += $globalResult->deletedCount;
        $truncated     = $truncated || $globalResult->truncated;

        $this->metrics?->recordRunsPruned($globalResult->deletedCount, null);
        $this->audit?->onRunsPruned($actorId, null, $globalResult->deletedCount, $globalCutoff, $globalResult->truncated);

        // Also drain terminal fire-queue rows. Kept separate from the run tally
        // above (the result is about run history); the pruner is a no-op when
        // fire-queue retention is disabled or unwired.
        $this->fireQueuePruner?->prune();

        return new RunRetentionSweepResult($totalDeleted, $truncated);
    }
}
