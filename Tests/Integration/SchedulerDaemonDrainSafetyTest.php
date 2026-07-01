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
 * Integration tests for SchedulerDaemon drain safety and lifecycle stability.
 *
 * Covers:
 *  - stop() is safe to call at any time and is idempotent
 *  - After runOnce(), the daemon still holds its shard lease (not auto-released)
 *  - Multiple runOnce() calls succeed — lease is renewed on each cycle
 *  - Competing daemon remains blocked after leader calls runOnce() multiple times
 *  - stop() + runOnce() does not crash (runOnce() does not check $running)
 *  - Lease is retained between runOnce() calls (no premature release)
 */
final class SchedulerDaemonDrainSafetyTest extends TestCase
{
    private const RUNS_TABLE = 'vortos_scheduler_runs';

    private Connection                 $connection;
    private RecordingSchedulerEnqueuer $enqueuer;
    private ClockPort                  $clock;

    /** @var list<ScheduleId> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->enqueuer   = new RecordingSchedulerEnqueuer();
        $this->clock      = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        $this->cleanCreatedRows();
    }

    // ─────────────────────────────────────────────────────────────
    // stop() idempotency and safety
    // ─────────────────────────────────────────────────────────────

    public function test_stop_before_any_work_does_not_throw(): void
    {
        $daemon = $this->makeDaemon();

        $daemon->stop();

        self::assertTrue(true); // stop() must not throw
    }

    public function test_stop_called_multiple_times_is_idempotent(): void
    {
        $daemon = $this->makeDaemon();

        $daemon->stop();
        $daemon->stop();
        $daemon->stop();
        $daemon->stop();
        $daemon->stop();

        self::assertTrue(true);
    }

    public function test_stop_after_run_once_does_not_throw(): void
    {
        $daemon = $this->makeDaemon();

        $daemon->runOnce();
        $daemon->stop();
        $daemon->stop();

        self::assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────
    // runOnce() does not check $running — safe after stop()
    // ─────────────────────────────────────────────────────────────

    public function test_run_once_still_works_after_stop_is_called(): void
    {
        $daemon = $this->makeDaemon();

        $daemon->stop();

        // runOnce() does NOT check $running; it should execute normally.
        self::assertTrue($daemon->runOnce());
    }

    public function test_run_once_after_stop_still_dispatches_due_fires(): void
    {
        $schedule = $this->makeDueSchedule('drain-dispatch', 'tenant-a');
        $daemon   = $this->makeDaemon(schedules: [$schedule]);

        $daemon->stop();
        $daemon->runOnce();

        self::assertSame(1, $this->enqueuer->count(), 'stop() must not prevent runOnce() from dispatching fires');
    }

    // ─────────────────────────────────────────────────────────────
    // Lease stability across multiple runOnce() calls
    // ─────────────────────────────────────────────────────────────

    public function test_lease_retained_across_multiple_run_once_calls(): void
    {
        $leaseStore = new InMemoryLeaseStore($this->clock);
        $daemonA    = $this->makeDaemon(leaseStore: $leaseStore);
        $daemonB    = $this->makeDaemon(leaseStore: $leaseStore);

        // Daemon A acquires the lease.
        self::assertTrue($daemonA->runOnce());

        // Daemon A renews multiple times — it should still hold the lease.
        self::assertTrue($daemonA->runOnce());
        self::assertTrue($daemonA->runOnce());
        self::assertTrue($daemonA->runOnce());

        // Daemon B must still be blocked.
        self::assertFalse($daemonB->runOnce(), 'Daemon B must remain blocked after daemon A renews the lease');
    }

    public function test_competing_daemon_blocked_after_ten_run_once_cycles(): void
    {
        $leaseStore = new InMemoryLeaseStore($this->clock);
        $daemonA    = $this->makeDaemon(leaseStore: $leaseStore);
        $daemonB    = $this->makeDaemon(leaseStore: $leaseStore);

        for ($i = 0; $i < 10; $i++) {
            self::assertTrue($daemonA->runOnce(), "Daemon A must hold lease on cycle {$i}");
        }

        self::assertFalse($daemonB->runOnce(), 'Daemon B must be blocked after 10 leader cycles');
    }

    // ─────────────────────────────────────────────────────────────
    // Dispatch idempotency over multiple cycles (combined drain check)
    // ─────────────────────────────────────────────────────────────

    public function test_fire_not_re_dispatched_across_drain_cycles(): void
    {
        $schedule   = $this->makeDueSchedule('drain-idempotent', 'tenant-a');
        $leaseStore = new InMemoryLeaseStore($this->clock);
        $daemon     = $this->makeDaemon(schedules: [$schedule], leaseStore: $leaseStore);

        $daemon->runOnce(); // dispatches
        $this->enqueuer->reset();

        // Simulate several more ticks (as if the daemon keeps running).
        $daemon->runOnce(); // idempotent — no new fire
        $daemon->runOnce(); // idempotent
        $daemon->runOnce(); // idempotent

        self::assertSame(
            0,
            $this->enqueuer->count(),
            'Fire must not be re-dispatched on subsequent runOnce() cycles',
        );
    }

    // ─────────────────────────────────────────────────────────────
    // stop() + runOnce() combination preserves dispatch semantics
    // ─────────────────────────────────────────────────────────────

    public function test_stop_and_run_once_alternating_stays_correct(): void
    {
        $schedule = $this->makeDueSchedule('stop-and-run', 'tenant-a');
        $daemon   = $this->makeDaemon(schedules: [$schedule]);

        // Interleave stop() calls between runOnce() — must not corrupt internal state.
        $daemon->stop();
        self::assertTrue($daemon->runOnce()); // dispatches

        $daemon->stop();
        $this->enqueuer->reset();
        self::assertTrue($daemon->runOnce()); // idempotent

        $daemon->stop();
        self::assertSame(0, $this->enqueuer->count());
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────

    /** @param list<Schedule> $schedules */
    private function makeDaemon(
        array               $schedules  = [],
        ?InMemoryLeaseStore $leaseStore = null,
    ): SchedulerDaemon {
        $clock      = $this->clock;
        $leaseStore ??= new InMemoryLeaseStore($clock);

        $slotCalc   = new SlotCalculator();
        $resolver   = new MisfireResolver($slotCalc);
        $dueScan    = new DueScan($resolver, 86400);
        $runStore   = new DbalScheduleRunStore($this->connection, self::RUNS_TABLE);

        $dispatcher = new FireDispatcher(
            runStore:          $runStore,
            enqueuer:          $this->enqueuer,
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
        $id      = ScheduleId::generate();
        $fireAt  = (new DateTimeImmutable('2026-07-01T10:00:00Z'))->modify('-30 seconds');

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
