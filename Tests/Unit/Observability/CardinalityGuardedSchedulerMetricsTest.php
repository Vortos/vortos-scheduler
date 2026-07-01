<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Observability\CardinalityGuardedSchedulerMetrics;
use Vortos\Scheduler\Observability\SchedulerMetricsPort;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;

/**
 * Unit tests for CardinalityGuardedSchedulerMetrics (E1).
 *
 * The guard limits distinct schedule_id Prometheus label values to maxDistinctSchedules (default 200).
 * Schedules beyond that limit are bucketed under the __overflow__ sentinel and the overflow counter
 * is incremented via the vortos_scheduler_metric_overflow_total metric.
 */
final class CardinalityGuardedSchedulerMetricsTest extends TestCase
{
    private SpyMetricsPort $spy;
    private CardinalityGuardedSchedulerMetrics $guard;

    protected function setUp(): void
    {
        $this->spy   = new SpyMetricsPort();
        $this->guard = new CardinalityGuardedSchedulerMetrics(
            inner:               $this->spy,
            metrics:             null,
            maxDistinctSchedules: 3,
        );
    }

    public function test_known_schedule_id_passes_through(): void
    {
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'schedule-a', null);

        self::assertSame('schedule-a', $this->spy->lastFireResultScheduleId);
    }

    public function test_second_distinct_id_also_passes_through(): void
    {
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'a', null);
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'b', null);

        self::assertSame('b', $this->spy->lastFireResultScheduleId);
    }

    public function test_exceeding_max_distinct_replaces_with_overflow_sentinel(): void
    {
        // Fill up 3 slots
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'a', null);
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'b', null);
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'c', null);

        // 4th distinct id should be bucketed under __overflow__
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'd', null);

        self::assertSame(
            CardinalityGuardedSchedulerMetrics::OVERFLOW_SENTINEL,
            $this->spy->lastFireResultScheduleId,
            'The 4th distinct id must be replaced with the overflow sentinel',
        );
    }

    public function test_known_id_still_passes_through_after_overflow(): void
    {
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'a', null);
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'b', null);
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'c', null);
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'd', null); // overflow

        // 'a' is already known — should still pass
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'a', null);

        self::assertSame('a', $this->spy->lastFireResultScheduleId);
    }

    public function test_dispatch_lag_uses_sanitized_id(): void
    {
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'a', null);
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'b', null);
        $this->guard->recordFireResult(FireDispatchResult::Dispatched, 'c', null);

        $this->guard->recordDispatchLag(500, 'd', null); // 'd' is overflow

        self::assertSame(CardinalityGuardedSchedulerMetrics::OVERFLOW_SENTINEL, $this->spy->lastLagScheduleId);
    }

    public function test_misfire_uses_sanitized_id(): void
    {
        $this->guard->recordMisfire(MisfirePolicy::skipMissed(), 'new-id', 'tenant');

        self::assertSame('new-id', $this->spy->lastMisfireScheduleId);
    }

    public function test_all_non_schedule_methods_delegate_unchanged(): void
    {
        $this->guard->recordLeaseContention(0);
        $this->guard->recordLeaderAcquired(1);
        $this->guard->recordLeaderLost(2);
        $this->guard->recordActiveSchedules(42);
        $this->guard->recordFairnessThrottle('tenant');
        $this->guard->recordAuditFailure('fire_dispatched');

        self::assertTrue($this->spy->leaseContentionCalled);
        self::assertTrue($this->spy->leaderAcquiredCalled);
        self::assertTrue($this->spy->leaderLostCalled);
        self::assertSame(42, $this->spy->lastActiveScheduleCount);
        self::assertTrue($this->spy->fairnessThrottleCalled);
        self::assertTrue($this->spy->auditFailureCalled);
    }

    public function test_max_distinct_of_one_allows_single_id(): void
    {
        $guard = new CardinalityGuardedSchedulerMetrics($this->spy, null, 1);
        $guard->recordFireResult(FireDispatchResult::Dispatched, 'only', null);

        self::assertSame('only', $this->spy->lastFireResultScheduleId);
    }

    public function test_max_distinct_of_one_overflows_second_id(): void
    {
        $guard = new CardinalityGuardedSchedulerMetrics($this->spy, null, 1);
        $guard->recordFireResult(FireDispatchResult::Dispatched, 'only', null);
        $guard->recordFireResult(FireDispatchResult::Dispatched, 'other', null);

        self::assertSame(CardinalityGuardedSchedulerMetrics::OVERFLOW_SENTINEL, $this->spy->lastFireResultScheduleId);
    }
}

// ── In-process spy ────────────────────────────────────────────────────────────

final class SpyMetricsPort implements SchedulerMetricsPort
{
    public ?string $lastFireResultScheduleId = null;
    public ?string $lastLagScheduleId        = null;
    public ?string $lastMisfireScheduleId    = null;
    public int     $lastActiveScheduleCount  = 0;
    public bool    $leaseContentionCalled    = false;
    public bool    $leaderAcquiredCalled     = false;
    public bool    $leaderLostCalled         = false;
    public bool    $fairnessThrottleCalled   = false;
    public bool    $auditFailureCalled       = false;

    public function recordFireResult(FireDispatchResult $result, string $scheduleId, ?string $tenantId): void
    {
        $this->lastFireResultScheduleId = $scheduleId;
    }

    public function recordMisfire(MisfirePolicy $policy, string $scheduleId, ?string $tenantId): void
    {
        $this->lastMisfireScheduleId = $scheduleId;
    }

    public function recordDispatchLag(int $lagMs, string $scheduleId, ?string $tenantId): void
    {
        $this->lastLagScheduleId = $scheduleId;
    }

    public function recordLeaseContention(int $shardIndex): void
    {
        $this->leaseContentionCalled = true;
    }

    public function recordLeaderAcquired(int $shardIndex): void
    {
        $this->leaderAcquiredCalled = true;
    }

    public function recordLeaderLost(int $shardIndex): void
    {
        $this->leaderLostCalled = true;
    }

    public function recordActiveSchedules(int $count): void
    {
        $this->lastActiveScheduleCount = $count;
    }

    public function recordFairnessThrottle(?string $tenantId): void
    {
        $this->fairnessThrottleCalled = true;
    }

    public function recordAuditFailure(string $eventType): void
    {
        $this->auditFailureCalled = true;
    }
}
