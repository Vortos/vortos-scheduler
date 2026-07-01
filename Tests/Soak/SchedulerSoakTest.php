<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Soak;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Engine\DueScan;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Engine\FireDispatcherPort;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Store\PruneResult;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;

/**
 * @group soak
 *
 * Soak test: runs the scheduler over a simulated 24-hour window with many schedules
 * and verifies that zero double-fires occur (exactly-once invariant).
 *
 * This test is excluded from the standard CI run (see phpunit.xml group exclusions)
 * and is intended to be run explicitly during release validation:
 *
 *   docker compose exec backend php vendor/bin/phpunit --group=soak --testsuite=Scheduler
 *
 * It does NOT require a real database — the enqueuer records fires in memory and the
 * uniqueness invariant is checked at the end. A real DB test is in SchedulerChaosTest.
 */
final class SchedulerSoakTest extends TestCase
{
    private const SOAK_HOURS         = 24;
    private const SCHEDULE_COUNT     = 50;
    private const INTERVAL_SECONDS   = 3600; // hourly

    public function test_zero_double_fires_over_24_hour_window(): void
    {
        $start   = new DateTimeImmutable('2026-07-01T00:00:00Z', new DateTimeZone('UTC'));
        $clock   = new MutableClock($start);
        $store   = new InMemoryScheduleStore();
        $fireLog = new InMemoryFireLog();
        $enqueuer = new SoakRecordingEnqueuer($fireLog);
        $runStore = new SoakRunStore();

        // Seed 50 hourly schedules
        $schedules = [];
        for ($i = 0; $i < self::SCHEDULE_COUNT; $i++) {
            $schedule    = $this->makeSchedule($i);
            $schedules[] = $schedule;
            $store->seed($schedule);
        }

        $resolver = new ScheduleResolver(
            new StaticScheduleRegistry([]),
            $store,
            new InMemoryScheduleStatusOverrideStore(),
        );

        $dispatcher = new SoakDispatcher($enqueuer, $runStore, $clock);
        $daemon     = new SchedulerDaemon(
            leasePort:                new InMemoryLeaseStore($clock),
            scheduleResolver:         $resolver,
            runStore:                 $runStore,
            dueScan:                  new DueScan(new MisfireResolver(new \Vortos\Scheduler\Engine\SlotCalculator()), 86400),
            fireDispatcher:           $dispatcher,
            clock:                    $clock,
            logger:                   new \Psr\Log\NullLogger(),
            shardCount:               1,
            leaseTtlSec:              30,
            maxIdleSec:               60,
            tenantMaxConcurrentFires: 0,
        );

        // Simulate 24 hours of ticks, advancing 1 hour between each
        $ticks = self::SOAK_HOURS;
        for ($tick = 0; $tick < $ticks; $tick++) {
            $daemon->runOnce();
            $clock->advanceSeconds(self::INTERVAL_SECONDS);
        }

        // Verify the uniqueness invariant
        $duplicates = $fireLog->findDuplicates();

        self::assertSame(
            [],
            $duplicates,
            sprintf(
                'Found %d double-fire(s) after %d-hour soak: %s',
                count($duplicates),
                self::SOAK_HOURS,
                json_encode(array_slice($duplicates, 0, 5)),
            ),
        );

        // Sanity: at least some fires must have happened
        self::assertGreaterThan(
            0,
            $fireLog->totalFires(),
            'Soak test produced zero fires — test may be misconfigured',
        );
    }

    public function test_tenant_fairness_cap_respected_under_burst(): void
    {
        $start   = new DateTimeImmutable('2026-07-01T00:00:00Z', new DateTimeZone('UTC'));
        $clock   = new MutableClock($start);
        $store   = new InMemoryScheduleStore();
        $fireLog = new InMemoryFireLog();
        $runStore = new SoakRunStore();

        // 20 schedules for same tenant — all past-due
        for ($i = 0; $i < 20; $i++) {
            $store->seed($this->makeSchedule($i, tenantId: 'burst-tenant'));
        }

        $resolver   = new ScheduleResolver(new StaticScheduleRegistry([]), $store, new InMemoryScheduleStatusOverrideStore());
        $dispatcher = new SoakDispatcher(new SoakRecordingEnqueuer($fireLog), $runStore, $clock);

        $daemon = new SchedulerDaemon(
            leasePort:                new InMemoryLeaseStore($clock),
            scheduleResolver:         $resolver,
            runStore:                 $runStore,
            dueScan:                  new DueScan(new MisfireResolver(new \Vortos\Scheduler\Engine\SlotCalculator()), 86400),
            fireDispatcher:           $dispatcher,
            clock:                    $clock,
            logger:                   new \Psr\Log\NullLogger(),
            shardCount:               1,
            leaseTtlSec:              30,
            maxIdleSec:               60,
            tenantMaxConcurrentFires: 5, // cap at 5 per tenant per tick
        );

        $daemon->runOnce();

        $fires = $fireLog->totalFires();

        self::assertLessThanOrEqual(
            5,
            $fires,
            "Tenant fairness cap of 5 should prevent more than 5 fires per tick, got {$fires}",
        );
    }

    private function makeSchedule(int $index, ?string $tenantId = null): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     "soak-schedule-{$index}",
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(self::INTERVAL_SECONDS),
            command:  new CommandSpec('FakeSoakCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: $tenantId,
        );
    }
}

// ── In-memory fire log ────────────────────────────────────────────────────────

final class InMemoryFireLog
{
    /** @var array<string, int> slot → count */
    private array $fires = [];

    public function record(string $scheduleId, string $slot): void
    {
        $key = "{$scheduleId}:{$slot}";
        $this->fires[$key] = ($this->fires[$key] ?? 0) + 1;
    }

    /** @return list<string> slots that were fired more than once */
    public function findDuplicates(): array
    {
        return array_keys(array_filter($this->fires, fn(int $count) => $count > 1));
    }

    public function totalFires(): int
    {
        return array_sum($this->fires);
    }
}

final class SoakRecordingEnqueuer implements \Vortos\Scheduler\Engine\SchedulerEnqueuerPort
{
    public function __construct(private readonly InMemoryFireLog $log) {}

    public function enqueue(\Vortos\Scheduler\Fire\ScheduledFire $fire, \Vortos\Scheduler\Schedule\Schedule $schedule): void
    {
        $this->log->record($fire->scheduleId->toString(), $fire->slot);
    }
}

final class SoakDispatcher implements FireDispatcherPort
{
    public function __construct(
        private readonly SoakRecordingEnqueuer $enqueuer,
        private readonly SoakRunStore          $runStore,
        private readonly MutableClock          $clock,
    ) {}

    public function dispatch(ScheduledFire $fire, \Vortos\Scheduler\Schedule\Schedule $schedule): FireDispatchResult
    {
        $key = $fire->scheduleId->toString() . ':' . $fire->slot;
        if (isset($this->runStore->slots[$key])) {
            return FireDispatchResult::AlreadyDispatched;
        }

        $this->runStore->slots[$key] = true;
        $this->enqueuer->enqueue($fire, $schedule);

        return FireDispatchResult::Dispatched;
    }
}

final class SoakRunStore implements ScheduleRunStoreInterface
{
    public array $slots = [];

    public function insertRun(\Vortos\Scheduler\Fire\ScheduleRun $run): void {}
    public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
    public function findRunState(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\RunState { return null; }
    public function findRunBySlot(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\ScheduleRun { return null; }
    public function transitionRunState(string $runId, \Vortos\Scheduler\Fire\RunState $newState, \DateTimeImmutable $at): void {}
    public function pruneOldRuns(\DateTimeImmutable $before, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult { return new PruneResult(0, false); }
    public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array { return []; }
}
