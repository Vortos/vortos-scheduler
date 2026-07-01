<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Observability;

use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;

/**
 * PORT — all scheduler metric emission operations.
 *
 * Extracted so {@see CardinalityGuardedSchedulerMetrics} can wrap
 * {@see SchedulerMetrics} without subclassing the concrete class.
 * The daemon and all callers depend on this interface, not the concrete class.
 */
interface SchedulerMetricsPort
{
    public function recordFireResult(FireDispatchResult $result, string $scheduleId, ?string $tenantId): void;

    public function recordMisfire(MisfirePolicy $policy, string $scheduleId, ?string $tenantId): void;

    public function recordDispatchLag(int $lagMs, string $scheduleId, ?string $tenantId): void;

    public function recordLeaseContention(int $shardIndex): void;

    public function recordLeaderAcquired(int $shardIndex): void;

    public function recordLeaderLost(int $shardIndex): void;

    public function recordActiveSchedules(int $count): void;

    public function recordFairnessThrottle(?string $tenantId): void;

    public function recordAuditFailure(string $eventType): void;

    /** S12 — result of executing one fire-queue row through the CQRS CommandBus. */
    public function recordConsumeResult(bool $success, string $scheduleId, ?string $tenantId): void;

    /** Rows deleted by one pruneOldRuns() call (auto or manual). */
    public function recordRunsPruned(int $count, ?string $tenantId): void;

    /** Terminal fire-queue rows deleted by FireQueuePruner (global — no tenant dimension). */
    public function recordFireQueuePruned(int $count): void;

    /** Wall-clock duration of one full prune sweep (all tenants/chunks summed). */
    public function recordPruneDuration(float $seconds, string $trigger): void;
}
