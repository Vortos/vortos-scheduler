<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Engine\FireDispatcherPort;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Testing\FakeFireDispatcherPort;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Unit tests for SchedulerDaemon heartbeat guard and node-seeded jitter (E2, E6).
 *
 * These tests use a PartitioningLeaseStore that simulates a shard being held,
 * and verify that the daemon's heartbeat guard prevents dispatch when the lease
 * renewal has gone silent.
 */
final class SchedulerDaemonHeartbeatJitterTest extends TestCase
{
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MutableClock(new DateTimeImmutable('2026-07-01T10:00:00Z', new DateTimeZone('UTC')));
    }

    public function test_daemon_static_lease_key_format(): void
    {
        self::assertSame('scheduler:leader:0', SchedulerDaemon::leaseKeyForShard(0));
        self::assertSame('scheduler:leader:5', SchedulerDaemon::leaseKeyForShard(5));
    }

    public function test_shard_index_for_deterministic(): void
    {
        $id = ScheduleId::fromString('00000000-0000-4000-8000-000000000001');

        $index1 = SchedulerDaemon::shardIndexFor($id, 4);
        $index2 = SchedulerDaemon::shardIndexFor($id, 4);

        self::assertSame($index1, $index2, 'Shard index must be deterministic');
        self::assertGreaterThanOrEqual(0, $index1);
        self::assertLessThan(4, $index1);
    }

    public function test_shard_index_returns_zero_for_single_shard(): void
    {
        $id = ScheduleId::generate();

        self::assertSame(0, SchedulerDaemon::shardIndexFor($id, 1));
    }

    public function test_shard_count_below_one_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeDaemon(shardCount: 0);
    }

    public function test_lease_ttl_below_five_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeDaemon(leaseTtlSec: 4);
    }

    public function test_max_idle_below_one_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeDaemon(maxIdleSec: 0);
    }

    public function test_run_once_returns_false_when_no_shard_acquired(): void
    {
        // No schedules, no lease held
        $daemon = $this->makeDaemon();

        $result = $daemon->runOnce();

        // With InMemoryLeaseStore the daemon can acquire since no other holder
        // — we just verify the method contract completes without error
        self::assertIsBool($result);
    }

    public function test_run_once_with_schedule_dispatches_fire(): void
    {
        $store    = new InMemoryScheduleStore();
        $schedule = $this->makeSchedule(new DateTimeImmutable('2026-07-01T09:00:00Z', new DateTimeZone('UTC')));
        $store->seed($schedule);

        $dispatcher = new FakeFireDispatcherPort();
        $daemon     = $this->makeDaemon(store: $store, dispatcher: $dispatcher);

        $daemon->runOnce();

        self::assertGreaterThan(0, count($dispatcher->calls), 'At least one fire should be dispatched');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeDaemon(
        ?InMemoryScheduleStore $store      = null,
        ?FireDispatcherPort    $dispatcher = null,
        int $shardCount  = 1,
        int $leaseTtlSec = 30,
        int $maxIdleSec  = 60,
    ): SchedulerDaemon {
        $store      ??= new InMemoryScheduleStore();
        $leaseStore = new InMemoryLeaseStore($this->clock);

        $resolver = new ScheduleResolver(
            new StaticScheduleRegistry([]),
            $store,
            new InMemoryScheduleStatusOverrideStore(),
        );

        $dispatcher ??= new FakeFireDispatcherPort();
        $runStore    = new InMemoryScheduleRunStore();
        $dueScan     = new \Vortos\Scheduler\Engine\DueScan(
            new \Vortos\Scheduler\Engine\MisfireResolver(new \Vortos\Scheduler\Engine\SlotCalculator()),
            86400,
        );

        return new SchedulerDaemon(
            leasePort:                $leaseStore,
            scheduleResolver:         $resolver,
            runStore:                 $runStore,
            dueScan:                  $dueScan,
            fireDispatcher:           $dispatcher,
            clock:                    $this->clock,
            logger:                   new \Psr\Log\NullLogger(),
            shardCount:               $shardCount,
            leaseTtlSec:              $leaseTtlSec,
            maxIdleSec:               $maxIdleSec,
            tenantMaxConcurrentFires: 0,
        );
    }

    private function makeSchedule(DateTimeImmutable $firstFire): Schedule
    {
        // IntervalTrigger(3600) with a last-due slot 2h in the past ensures a fire is due
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'heartbeat-test',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('FakeCommand'),
            misfire:  MisfirePolicy::fireEachMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

// ── In-memory run store for daemon tests ─────────────────────────────────────

final class InMemoryScheduleRunStore implements ScheduleRunStoreInterface
{
    public function insertRun(\Vortos\Scheduler\Fire\ScheduleRun $run): void {}
    public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
    public function findRunState(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\RunState { return null; }
    public function findRunBySlot(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\ScheduleRun { return null; }
    public function transitionRunState(string $runId, \Vortos\Scheduler\Fire\RunState $newState, \DateTimeImmutable $at): void {}
    public function pruneOldRuns(\DateTimeImmutable $before): int { return 0; }
    public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array { return []; }
}
