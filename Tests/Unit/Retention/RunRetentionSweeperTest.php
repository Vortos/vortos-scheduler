<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Retention;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Observability\SchedulerTracer;
use Vortos\Scheduler\Retention\RunRetentionSweeper;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\PruneResult;
use Vortos\Scheduler\Store\RunRetentionOverride;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Testing\InMemoryRunRetentionOverrideStore;
use Vortos\Scheduler\Testing\RecordingSchedulerMetrics;

final class RunRetentionSweeperTest extends TestCase
{
    private RecordingRunStore                 $runStore;
    private InMemoryRunRetentionOverrideStore $overrideStore;
    private MutableClock                      $clock;

    protected function setUp(): void
    {
        $this->runStore     = new RecordingRunStore();
        $this->overrideStore = new InMemoryRunRetentionOverrideStore();
        $this->clock         = new MutableClock(new DateTimeImmutable('2026-07-01T03:00:00Z'));
    }

    public function test_no_overrides_prunes_once_at_global_default(): void
    {
        $sweeper = $this->makeSweeper(globalRetentionDays: 30);

        $result = $sweeper->sweep('auto');

        self::assertCount(1, $this->runStore->calls);
        self::assertNull($this->runStore->calls[0]['tenantId']);
        self::assertSame([], $this->runStore->calls[0]['excludeTenantIds']);
        self::assertSame('2026-06-01', $this->runStore->calls[0]['before']->format('Y-m-d'));
        self::assertSame(0, $result->deletedCount);
        self::assertFalse($result->truncated);
    }

    public function test_tenant_override_prunes_at_its_own_cutoff(): void
    {
        $this->overrideStore->save(new RunRetentionOverride('tenant-a', 90, 'admin', new DateTimeImmutable()));
        $sweeper = $this->makeSweeper(globalRetentionDays: 30);

        $sweeper->sweep('auto');

        self::assertCount(2, $this->runStore->calls);

        $tenantCall = $this->runStore->calls[0];
        self::assertSame('tenant-a', $tenantCall['tenantId']);
        self::assertSame('2026-04-02', $tenantCall['before']->format('Y-m-d'));

        $globalCall = $this->runStore->calls[1];
        self::assertNull($globalCall['tenantId']);
        self::assertSame(['tenant-a'], $globalCall['excludeTenantIds']);
    }

    public function test_zero_retention_override_is_skipped_never_pruned(): void
    {
        $this->overrideStore->save(new RunRetentionOverride('tenant-hold', 0, 'compliance', new DateTimeImmutable()));
        $sweeper = $this->makeSweeper(globalRetentionDays: 30);

        $sweeper->sweep('auto');

        // Only the global sweep call — tenant-hold never gets its own pruneOldRuns() call.
        self::assertCount(1, $this->runStore->calls);
        // But it IS excluded from the global "everyone else" sweep — it must never be pruned at all.
        self::assertSame(['tenant-hold'], $this->runStore->calls[0]['excludeTenantIds']);
    }

    public function test_multiple_overrides_each_get_their_own_cutoff(): void
    {
        $this->overrideStore->save(new RunRetentionOverride('tenant-a', 90, 'a', new DateTimeImmutable()));
        $this->overrideStore->save(new RunRetentionOverride('tenant-b', 7, 'b', new DateTimeImmutable()));
        $sweeper = $this->makeSweeper(globalRetentionDays: 30);

        $sweeper->sweep('auto');

        self::assertCount(3, $this->runStore->calls); // tenant-a, tenant-b, global
        $globalCall = $this->runStore->calls[2];
        self::assertNull($globalCall['tenantId']);
        self::assertEqualsCanonicalizing(['tenant-a', 'tenant-b'], $globalCall['excludeTenantIds']);
    }

    public function test_truncated_propagates_when_any_call_is_truncated(): void
    {
        $this->runStore->nextResults = [
            new PruneResult(5000, true), // global call truncated
        ];
        $sweeper = $this->makeSweeper(globalRetentionDays: 30);

        $result = $sweeper->sweep('auto');

        self::assertTrue($result->truncated);
        self::assertSame(5000, $result->deletedCount);
    }

    public function test_deleted_count_sums_across_all_calls(): void
    {
        $this->overrideStore->save(new RunRetentionOverride('tenant-a', 90, 'a', new DateTimeImmutable()));
        $this->runStore->nextResults = [
            new PruneResult(10, false), // tenant-a
            new PruneResult(25, false), // global
        ];
        $sweeper = $this->makeSweeper(globalRetentionDays: 30);

        $result = $sweeper->sweep('auto');

        self::assertSame(35, $result->deletedCount);
    }

    public function test_fire_queue_pruner_is_invoked_by_the_sweep(): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE fq (id TEXT PRIMARY KEY, status TEXT NOT NULL, dispatched_at DATETIME NULL)',
        );
        // One terminal row well past any cutoff — the sweep must reach the pruner and delete it.
        $connection->insert('fq', ['id' => 'old', 'status' => 'dispatched', 'dispatched_at' => '2026-01-01 00:00:00']);

        $pruner  = new \Vortos\Scheduler\Retention\FireQueuePruner(
            connection:    $connection,
            clock:         $this->clock,
            retentionDays: 7,
            table:         'fq',
        );
        $sweeper = $this->makeSweeper(globalRetentionDays: 30, fireQueuePruner: $pruner);

        $sweeper->sweep('auto');

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM fq'));
    }

    public function test_absent_fire_queue_pruner_is_a_no_op(): void
    {
        // Default (null) pruner — sweep must not error.
        $result = $this->makeSweeper(globalRetentionDays: 30)->sweep('auto');

        self::assertSame(0, $result->deletedCount);
    }

    public function test_metrics_recorded_per_scope(): void
    {
        $this->overrideStore->save(new RunRetentionOverride('tenant-a', 90, 'a', new DateTimeImmutable()));
        $this->runStore->nextResults = [new PruneResult(3, false), new PruneResult(7, false)];
        $recording = new RecordingSchedulerMetrics();

        $sweeper = $this->makeSweeper(globalRetentionDays: 30, metrics: $recording->schedulerMetrics);
        $sweeper->sweep('auto');

        $recording->assertCounterIncrementedWith('vortos_scheduler_runs_pruned_total', ['tenant_id' => 'tenant-a']);
        $recording->assertCounterIncrementedWith('vortos_scheduler_runs_pruned_total', ['tenant_id' => 'system']);
        $recording->assertHistogramObserved('vortos_scheduler_prune_duration_seconds');
        $this->addToAssertionCount(1);
    }

    private function makeSweeper(
        int $globalRetentionDays,
        ?\Vortos\Scheduler\Observability\SchedulerMetrics $metrics = null,
        ?\Vortos\Scheduler\Retention\FireQueuePruner $fireQueuePruner = null,
    ): RunRetentionSweeper {
        return new RunRetentionSweeper(
            runStore:            $this->runStore,
            overrideStore:       $this->overrideStore,
            clock:                $this->clock,
            tracer:               new SchedulerTracer(null),
            globalRetentionDays: $globalRetentionDays,
            audit:                null,
            metrics:              $metrics,
            fireQueuePruner:      $fireQueuePruner,
        );
    }
}

/** Records every pruneOldRuns() call for assertion; returns queued results in order. */
final class RecordingRunStore implements ScheduleRunStoreInterface
{
    /** @var list<array{before: DateTimeImmutable, tenantId: ?string, excludeTenantIds: list<string>}> */
    public array $calls = [];

    /** @var list<PruneResult> */
    public array $nextResults = [];

    public function insertRun(ScheduleRun $run): void {}
    public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
    public function findRunState(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?RunState { return null; }
    public function transitionRunState(string $runId, RunState $newState, DateTimeImmutable $at): void {}
    public function findRunBySlot(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?ScheduleRun { return null; }
    public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array { return []; }

    public function pruneOldRuns(DateTimeImmutable $before, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult
    {
        $this->calls[] = ['before' => $before, 'tenantId' => $tenantId, 'excludeTenantIds' => $excludeTenantIds];

        if ($this->nextResults !== []) {
            return array_shift($this->nextResults);
        }

        return new PruneResult(0, false);
    }
}
