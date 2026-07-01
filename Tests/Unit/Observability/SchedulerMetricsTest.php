<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Observability\SchedulerMetrics;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Testing\RecordingSchedulerMetrics;

final class SchedulerMetricsTest extends TestCase
{
    private RecordingSchedulerMetrics $recording;

    protected function setUp(): void
    {
        $this->recording = new RecordingSchedulerMetrics();
    }

    private function counters(): array { return $this->recording->counters; }
    private function histograms(): array { return $this->recording->histograms; }
    private function gauges(): array { return $this->recording->gauges; }

    private function assertCounter(string $name, array $labels = []): void
    {
        foreach ($this->counters() as $c) {
            if ($c['name'] !== $name) { continue; }
            $match = true;
            foreach ($labels as $k => $v) {
                if (($c['labels'][$k] ?? null) !== $v) { $match = false; break; }
            }
            if ($match) { $this->addToAssertionCount(1); return; }
        }
        self::fail("Counter '{$name}' with labels " . json_encode($labels) . " was not incremented. Recorded: " . json_encode($this->counters()));
    }

    // ── recordFireResult ──────────────────────────────────────────────────────

    public function test_record_fire_result_dispatched_increments_counter(): void
    {
        $this->recording->schedulerMetrics->recordFireResult(FireDispatchResult::Dispatched, 'sched-1', 'tenant-1');

        $this->assertCounter('vortos_scheduler_fires_total', ['result' => 'dispatched', 'schedule_id' => 'sched-1', 'tenant_id' => 'tenant-1']);
    }

    public function test_record_fire_result_already_dispatched_uses_correct_label(): void
    {
        $this->recording->schedulerMetrics->recordFireResult(FireDispatchResult::AlreadyDispatched, 'sched-2', null);

        $this->assertCounter('vortos_scheduler_fires_total', ['result' => 'already_dispatched', 'tenant_id' => 'system']);
    }

    public function test_record_fire_result_skipped_overlap(): void
    {
        $this->recording->schedulerMetrics->recordFireResult(FireDispatchResult::SkippedOverlap, 'sched-3', 'tenant-2');

        $this->assertCounter('vortos_scheduler_fires_total', ['result' => 'skipped_overlap']);
    }

    public function test_record_fire_result_deferred(): void
    {
        $this->recording->schedulerMetrics->recordFireResult(FireDispatchResult::Deferred, 'sched-4', null);

        $this->assertCounter('vortos_scheduler_fires_total', ['result' => 'deferred']);
    }

    public function test_null_tenant_uses_system_label(): void
    {
        $this->recording->schedulerMetrics->recordFireResult(FireDispatchResult::Dispatched, 'sched-x', null);

        $this->assertCounter('vortos_scheduler_fires_total', ['tenant_id' => 'system']);
    }

    public function test_fire_result_counter_count(): void
    {
        $this->recording->schedulerMetrics->recordFireResult(FireDispatchResult::Dispatched, 's', null);
        $this->recording->schedulerMetrics->recordFireResult(FireDispatchResult::SkippedOverlap, 's', null);

        self::assertCount(2, $this->counters());
    }

    // ── recordMisfire ─────────────────────────────────────────────────────────

    public function test_record_misfire_skip_missed(): void
    {
        $this->recording->schedulerMetrics->recordMisfire(MisfirePolicy::skipMissed(), 'sched-1', null);

        $this->assertCounter('vortos_scheduler_misfires_total', ['policy' => 'skip_missed']);
    }

    public function test_record_misfire_fire_each_missed(): void
    {
        $this->recording->schedulerMetrics->recordMisfire(MisfirePolicy::fireEachMissed(), 'sched-1', 'tenant-1');

        $this->assertCounter('vortos_scheduler_misfires_total', ['policy' => 'fire_each_missed']);
    }

    public function test_record_misfire_fire_once_now(): void
    {
        $this->recording->schedulerMetrics->recordMisfire(MisfirePolicy::fireOnceNow(), 'sched-1', null);

        $this->assertCounter('vortos_scheduler_misfires_total', ['policy' => 'fire_once_now']);
    }

    // ── recordDispatchLag ─────────────────────────────────────────────────────

    public function test_record_dispatch_lag_observes_histogram(): void
    {
        $this->recording->schedulerMetrics->recordDispatchLag(250, 'sched-1', 'tenant-1');

        self::assertCount(1, $this->histograms());
        self::assertSame('vortos_scheduler_dispatch_lag_ms', $this->histograms()[0]['name']);
        self::assertSame(250.0, $this->histograms()[0]['value']);
        self::assertSame('sched-1', $this->histograms()[0]['labels']['schedule_id']);
    }

    public function test_record_dispatch_lag_clamps_negative_to_zero(): void
    {
        $this->recording->schedulerMetrics->recordDispatchLag(-100, 'sched-1', null);

        self::assertCount(1, $this->histograms());
        self::assertSame(0.0, $this->histograms()[0]['value']);
    }

    // ── recordLeaseContention ─────────────────────────────────────────────────

    public function test_record_lease_contention(): void
    {
        $this->recording->schedulerMetrics->recordLeaseContention(3);

        $this->assertCounter('vortos_scheduler_lease_contention_total', ['shard' => '3']);
    }

    // ── recordLeaderAcquired / recordLeaderLost ───────────────────────────────

    public function test_record_leader_acquired(): void
    {
        $this->recording->schedulerMetrics->recordLeaderAcquired(0);

        $this->assertCounter('vortos_scheduler_leader_changes_total', ['shard' => '0', 'direction' => 'acquired']);
    }

    public function test_record_leader_lost(): void
    {
        $this->recording->schedulerMetrics->recordLeaderLost(1);

        $this->assertCounter('vortos_scheduler_leader_changes_total', ['shard' => '1', 'direction' => 'lost']);
    }

    // ── recordActiveSchedules ─────────────────────────────────────────────────

    public function test_record_active_schedules_sets_gauge(): void
    {
        $this->recording->schedulerMetrics->recordActiveSchedules(42);

        self::assertCount(1, $this->gauges());
        self::assertSame('vortos_scheduler_active_schedules', $this->gauges()[0]['name']);
        self::assertSame(42.0, $this->gauges()[0]['value']);
    }

    public function test_record_active_schedules_zero(): void
    {
        $this->recording->schedulerMetrics->recordActiveSchedules(0);

        self::assertCount(1, $this->gauges());
        self::assertSame(0.0, $this->gauges()[0]['value']);
    }

    // ── recordFairnessThrottle ────────────────────────────────────────────────

    public function test_record_fairness_throttle_with_tenant(): void
    {
        $this->recording->schedulerMetrics->recordFairnessThrottle('tenant-99');

        $this->assertCounter('vortos_scheduler_fairness_throttled_total', ['tenant_id' => 'tenant-99']);
    }

    public function test_record_fairness_throttle_null_tenant_uses_system(): void
    {
        $this->recording->schedulerMetrics->recordFairnessThrottle(null);

        $this->assertCounter('vortos_scheduler_fairness_throttled_total', ['tenant_id' => 'system']);
    }

    // ── recordAuditFailure ────────────────────────────────────────────────────

    public function test_record_audit_failure(): void
    {
        $this->recording->schedulerMetrics->recordAuditFailure('fire.dispatched');

        $this->assertCounter('vortos_scheduler_audit_failures_total', ['event_type' => 'fire.dispatched']);
    }

    // ── Null metrics (safety) ─────────────────────────────────────────────────

    public function test_null_metrics_all_methods_no_op(): void
    {
        $metrics = new SchedulerMetrics(null);

        $metrics->recordFireResult(FireDispatchResult::Dispatched, 's', null);
        $metrics->recordMisfire(MisfirePolicy::skipMissed(), 's', null);
        $metrics->recordDispatchLag(100, 's', null);
        $metrics->recordLeaseContention(0);
        $metrics->recordLeaderAcquired(0);
        $metrics->recordLeaderLost(0);
        $metrics->recordActiveSchedules(5);
        $metrics->recordFairnessThrottle(null);
        $metrics->recordAuditFailure('test');

        $this->addToAssertionCount(1);
    }

    // ── Broken backend: never throws ─────────────────────────────────────────

    public function test_broken_metrics_backend_does_not_propagate_exception(): void
    {
        $broken = new class implements MetricsInterface {
            public function counter(string $name, array $labels = []): CounterInterface
            {
                throw new \RuntimeException('metrics backend down');
            }

            public function gauge(string $name, array $labels = []): GaugeInterface
            {
                throw new \RuntimeException('metrics backend down');
            }

            public function histogram(string $name, array $labels = []): HistogramInterface
            {
                throw new \RuntimeException('metrics backend down');
            }
        };

        $metrics = new SchedulerMetrics($broken);

        $metrics->recordFireResult(FireDispatchResult::Dispatched, 's', null);
        $metrics->recordActiveSchedules(1);
        $metrics->recordDispatchLag(100, 's', null);

        $this->addToAssertionCount(1);
    }
}
