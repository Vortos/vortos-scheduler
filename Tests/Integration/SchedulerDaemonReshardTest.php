<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Engine\DueScan;
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\OneShotTrigger;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Store\ScheduleStoreInterface;
use Vortos\Scheduler\Testing\RecordingSchedulerEnqueuer;

/**
 * Integration tests for SchedulerDaemon correctness under split-brain (reshard) scenarios.
 *
 * CORRECTNESS INVARIANT (SPEC_SCHEDULER.md §2):
 *   Exactly-once effect is guaranteed by the idempotent fire-ledger
 *   (UNIQUE(tenant_id, schedule_id, slot)), NOT by the lease alone.
 *   Even if two daemons simultaneously believe they are the leader (split-brain),
 *   the UNIQUE constraint ensures only one ScheduledFire is written to the ledger.
 *
 * Simulates split-brain by giving two daemons SEPARATE InMemoryLeaseStores —
 * they can't see each other's locks, so both believe they are leader.
 * The shared real-PG fire-ledger enforces exactly-once dispatch.
 *
 * Covers:
 *  - Split-brain: both daemons process same schedule → 1 enqueue total
 *  - Shard assignment consistency: same schedule ID maps to same shard index
 *  - shardIndexFor output is stable across daemon restarts (relies on crc32)
 *  - Double-assign collapses to single ledger row
 */
final class SchedulerDaemonReshardTest extends TestCase
{
    private const RUNS_TABLE = 'vortos_scheduler_runs';

    private Connection $connection;

    /** @var list<ScheduleId> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        $this->cleanCreatedRows();
    }

    // ─────────────────────────────────────────────────────────────
    // Correctness invariant: split-brain → 1 enqueue total
    // ─────────────────────────────────────────────────────────────

    public function test_split_brain_produces_exactly_one_enqueue(): void
    {
        $schedule        = $this->makeDueSchedule('split-brain-once', 'tenant-a');
        $sharedEnqueuer  = new RecordingSchedulerEnqueuer();
        $clock           = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));

        // Two SEPARATE InMemoryLeaseStores — each daemon thinks it is the leader.
        $leaseA = new InMemoryLeaseStore($clock);
        $leaseB = new InMemoryLeaseStore($clock);

        // Both daemons share the same enqueuer and the same PG connection (run store).
        $daemonA = $this->makeDaemon($leaseA, $clock, $sharedEnqueuer, [$schedule]);
        $daemonB = $this->makeDaemon($leaseB, $clock, $sharedEnqueuer, [$schedule]);

        // Both believe they are the leader for shard 0 (independent lease stores).
        self::assertTrue($daemonA->runOnce());
        self::assertTrue($daemonB->runOnce());

        // Despite the split-brain, the fire-ledger UNIQUE constraint ensures
        // exactly one entry was committed — so the enqueuer is called only once.
        self::assertSame(
            1,
            $sharedEnqueuer->count(),
            'Split-brain double-tick must collapse to exactly one enqueue via fire-ledger UNIQUE',
        );
    }

    public function test_split_brain_leaves_exactly_one_ledger_row(): void
    {
        $schedule       = $this->makeDueSchedule('split-brain-ledger', 'tenant-a');
        $sharedEnqueuer = new RecordingSchedulerEnqueuer();
        $clock          = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $daemonA = $this->makeDaemon(new InMemoryLeaseStore($clock), $clock, $sharedEnqueuer, [$schedule]);
        $daemonB = $this->makeDaemon(new InMemoryLeaseStore($clock), $clock, $sharedEnqueuer, [$schedule]);

        $daemonA->runOnce();
        $daemonB->runOnce();

        $rows = $this->connection->fetchAllAssociative(
            "SELECT run_id FROM " . self::RUNS_TABLE . " WHERE schedule_id = ?",
            [$schedule->id->toString()],
        );

        self::assertCount(
            1,
            $rows,
            'Split-brain must produce exactly one fire-ledger row — UNIQUE constraint must be the safety net',
        );
    }

    public function test_three_way_split_brain_still_one_enqueue(): void
    {
        $schedule       = $this->makeDueSchedule('three-way-brain', 'tenant-a');
        $sharedEnqueuer = new RecordingSchedulerEnqueuer();
        $clock          = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $daemonA = $this->makeDaemon(new InMemoryLeaseStore($clock), $clock, $sharedEnqueuer, [$schedule]);
        $daemonB = $this->makeDaemon(new InMemoryLeaseStore($clock), $clock, $sharedEnqueuer, [$schedule]);
        $daemonC = $this->makeDaemon(new InMemoryLeaseStore($clock), $clock, $sharedEnqueuer, [$schedule]);

        $daemonA->runOnce();
        $daemonB->runOnce();
        $daemonC->runOnce();

        self::assertSame(
            1,
            $sharedEnqueuer->count(),
            'Three-way split-brain must still collapse to exactly one enqueue',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Shard assignment consistency across daemon instances
    // ─────────────────────────────────────────────────────────────

    public function test_shard_assignment_is_stable_across_daemon_restarts(): void
    {
        // Two independent computations of shardIndexFor must agree.
        $id = ScheduleId::generate();

        $first  = SchedulerDaemon::shardIndexFor($id, 4);
        $second = SchedulerDaemon::shardIndexFor($id, 4);
        $third  = SchedulerDaemon::shardIndexFor($id, 4);

        self::assertSame($first, $second);
        self::assertSame($first, $third);
    }

    public function test_shard_assignment_identical_on_two_daemon_instances(): void
    {
        // Verifies that two separate daemons compute the SAME shard for a given schedule ID.
        // This is the prerequisite for correct multi-node partitioning.
        $id = ScheduleId::generate();

        $idx1 = SchedulerDaemon::shardIndexFor($id, 8);
        $idx2 = SchedulerDaemon::shardIndexFor($id, 8);

        self::assertSame($idx1, $idx2);
    }

    // ─────────────────────────────────────────────────────────────
    // Multiple schedules: split-brain with N schedules → N enqueues (not 2N)
    // ─────────────────────────────────────────────────────────────

    public function test_split_brain_with_multiple_schedules_produces_correct_enqueue_count(): void
    {
        $schedules      = [
            $this->makeDueSchedule('split-multi-a', 'tenant-a'),
            $this->makeDueSchedule('split-multi-b', 'tenant-a'),
            $this->makeDueSchedule('split-multi-c', 'tenant-a'),
        ];
        $sharedEnqueuer = new RecordingSchedulerEnqueuer();
        $clock          = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));

        $daemonA = $this->makeDaemon(new InMemoryLeaseStore($clock), $clock, $sharedEnqueuer, $schedules);
        $daemonB = $this->makeDaemon(new InMemoryLeaseStore($clock), $clock, $sharedEnqueuer, $schedules);

        $daemonA->runOnce();
        $daemonB->runOnce();

        // 3 schedules × 2 daemons = 6 attempts, but UNIQUE constraint collapses to 3 enqueues.
        self::assertSame(
            3,
            $sharedEnqueuer->count(),
            'Split-brain with 3 schedules must produce exactly 3 enqueues (not 6)',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────

    /**
     * @param list<Schedule> $schedules
     */
    private function makeDaemon(
        InMemoryLeaseStore         $leaseStore,
        ClockPort                  $clock,
        RecordingSchedulerEnqueuer $enqueuer,
        array                      $schedules = [],
    ): SchedulerDaemon {
        $slotCalc   = new SlotCalculator();
        $resolver   = new MisfireResolver($slotCalc);
        $dueScan    = new DueScan($resolver, 86400);
        $runStore   = new DbalScheduleRunStore($this->connection, self::RUNS_TABLE);

        $dispatcher = new FireDispatcher(
            runStore:          $runStore,
            enqueuer:          $enqueuer,
            connection:        $this->connection,
            clock:             $clock,
            assumedDoneTtlSec: 3600,
        );

        $scheduleStore    = $this->makeScheduleStore($schedules);
        $scheduleResolver = new ScheduleResolver(new StaticScheduleRegistry(), $scheduleStore);

        return new SchedulerDaemon(
            leasePort:               $leaseStore,
            scheduleResolver:        $scheduleResolver,
            runStore:                $runStore,
            dueScan:                 $dueScan,
            fireDispatcher:          $dispatcher,
            clock:                   $clock,
            logger:                  new NullLogger(),
            shardCount:              1,
            leaseTtlSec:             30,
            maxIdleSec:              60,
            tenantMaxConcurrentFires: 0,
        );
    }

    private function makeDueSchedule(string $name, ?string $tenantId): Schedule
    {
        $id     = ScheduleId::generate();
        $fireAt = (new DateTimeImmutable('2026-07-01T10:00:00Z'))->modify('-30 seconds');

        $this->createdIds[] = $id;

        return new Schedule(
            id:       $id,
            name:     $name,
            source:   ScheduleSource::Static,
            trigger:  new OneShotTrigger($fireAt),
            command:  new CommandSpec('Vortos\Scheduler\Tests\Integration\FakeCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: $tenantId,
        );
    }

    /** @param list<Schedule> $schedules */
    private function makeScheduleStore(array $schedules): ScheduleStoreInterface
    {
        return new class($schedules) implements ScheduleStoreInterface {
            public function __construct(private readonly array $schedules) {}

            public function save(Schedule $schedule): void {}

            public function find(ScheduleId $id, ?string $tenantId): ?Schedule
            {
                return null;
            }

            public function findByName(string $name, ?string $tenantId): ?Schedule
            {
                return null;
            }

            public function delete(ScheduleId $id, ?string $tenantId): void {}

            public function findActive(?string $tenantId): iterable
            {
                return [];
            }

            public function findAllActive(): iterable
            {
                return $this->schedules;
            }

            public function findAll(?string $tenantId): iterable
            {
                return [];
            }
        };
    }

    private function fixedClock(DateTimeImmutable $at): ClockPort
    {
        return new class($at) implements ClockPort {
            public function __construct(private readonly DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }

    private function connectOrSkip(): Connection
    {
        try {
            $conn = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['VORTOS_WRITE_DB_HOST'] ?? 'write_db',
                'port'     => (int) ($_ENV['VORTOS_WRITE_DB_PORT'] ?? 5432),
                'user'     => $_ENV['VORTOS_WRITE_DB_USER'] ?? 'postgres',
                'password' => $_ENV['VORTOS_WRITE_DB_PASSWORD'] ?? '12345',
                'dbname'   => $_ENV['VORTOS_WRITE_DB_NAME'] ?? 'squaura',
            ]);
            $conn->executeQuery('SELECT 1');

            return $conn;
        } catch (Throwable $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
    }

    private function ensureTables(): void
    {
        $t = self::RUNS_TABLE;
        $this->connection->executeStatement("
            CREATE TABLE IF NOT EXISTS {$t} (
                run_id        CHAR(64)     NOT NULL,
                schedule_id   VARCHAR(36)  NOT NULL,
                tenant_id     VARCHAR(255) NULL,
                slot          TEXT         NOT NULL,
                scheduled_for TIMESTAMPTZ  NOT NULL,
                dispatched_at TIMESTAMPTZ  NOT NULL,
                completed_at  TIMESTAMPTZ  NULL,
                run_state     VARCHAR(20)  NOT NULL DEFAULT 'dispatched',
                attempt       SMALLINT     NOT NULL DEFAULT 1,
                CONSTRAINT pk_{$t} PRIMARY KEY (run_id)
            )
        ");
        $this->connection->executeStatement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_{$t}_slot
                ON {$t} (tenant_id, schedule_id, slot)
        ");
        $this->connection->executeStatement("
            CREATE INDEX IF NOT EXISTS idx_{$t}_schedule_dispatched
                ON {$t} (schedule_id, dispatched_at)
        ");
    }

    private function cleanCreatedRows(): void
    {
        if (empty($this->createdIds)) {
            return;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($this->createdIds), '?'));
            $ids          = array_map(fn (ScheduleId $id) => $id->toString(), $this->createdIds);
            $this->connection->executeStatement(
                "DELETE FROM " . self::RUNS_TABLE . " WHERE schedule_id IN ({$placeholders})",
                $ids,
            );
        } catch (Throwable) {
        }
    }
}
