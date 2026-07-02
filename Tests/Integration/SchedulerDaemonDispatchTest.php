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
use Vortos\Scheduler\Engine\SchedulerEnqueuerPort;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\OneShotTrigger;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Store\Dbal\DbalScheduleCursorStore;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Store\ScheduleStoreInterface;
use Vortos\Scheduler\Testing\RecordingSchedulerEnqueuer;

/**
 * Integration tests for SchedulerDaemon's dispatch lifecycle against real PostgreSQL.
 *
 * Covers:
 *  - Constructor validation (invalid args → InvalidArgumentException)
 *  - runOnce() returns true when shard held, false when lease blocked
 *  - Due fires dispatched exactly once (idempotency via fire-ledger UNIQUE constraint)
 *  - Second runOnce() on same slot returns AlreadyDispatched — no new enqueue
 *  - FireDispatchException per-fire is caught; daemon continues to remaining fires
 *  - Tenant ID propagated correctly to dispatched fires
 */
final class SchedulerDaemonDispatchTest extends TestCase
{
    private const RUNS_TABLE    = 'vortos_scheduler_runs';
    private const CURSORS_TABLE = 'vortos_scheduler_cursors';

    private Connection                 $connection;
    private DbalScheduleRunStore       $runStore;
    private DbalScheduleCursorStore    $cursorStore;
    private RecordingSchedulerEnqueuer $enqueuer;
    private ClockPort                  $clock;

    /** @var list<ScheduleId> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->runStore   = new DbalScheduleRunStore($this->connection, self::RUNS_TABLE);
        $this->cursorStore = new DbalScheduleCursorStore($this->connection, self::CURSORS_TABLE);
        $this->enqueuer   = new RecordingSchedulerEnqueuer();
        $this->clock      = $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        $this->cleanCreatedRows();
    }

    // ─────────────────────────────────────────────────────────────
    // Constructor validation
    // ─────────────────────────────────────────────────────────────

    public function test_constructor_rejects_shard_count_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/shardCount/');

        $this->makeDaemon(shardCount: 0);
    }

    public function test_constructor_rejects_negative_shard_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeDaemon(shardCount: -1);
    }

    public function test_constructor_rejects_lease_ttl_below_minimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/leaseTtlSec/');

        $this->makeDaemon(leaseTtlSec: 4);
    }

    public function test_constructor_rejects_max_idle_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/maxIdleSec/');

        $this->makeDaemon(maxIdleSec: 0);
    }

    public function test_constructor_rejects_negative_tenant_max_fires(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/tenantMaxConcurrentFires/');

        $this->makeDaemon(tenantMaxConcurrentFires: -1);
    }

    public function test_constructor_accepts_valid_arguments(): void
    {
        $daemon = $this->makeDaemon(shardCount: 2, leaseTtlSec: 10, maxIdleSec: 5, tenantMaxConcurrentFires: 3);

        // Construction did not throw — assertions handled by absence of exception.
        self::assertInstanceOf(SchedulerDaemon::class, $daemon);
    }

    public function test_constructor_accepts_boundary_values(): void
    {
        // Minimum valid values for each constraint.
        $daemon = $this->makeDaemon(shardCount: 1, leaseTtlSec: 5, maxIdleSec: 1, tenantMaxConcurrentFires: 0);

        self::assertInstanceOf(SchedulerDaemon::class, $daemon);
    }

    // ─────────────────────────────────────────────────────────────
    // runOnce — no schedules
    // ─────────────────────────────────────────────────────────────

    public function test_run_once_returns_true_when_shard_held_with_no_schedules(): void
    {
        $daemon = $this->makeDaemon(schedules: []);

        self::assertTrue($daemon->runOnce());
        self::assertSame(0, $this->enqueuer->count());
    }

    // ─────────────────────────────────────────────────────────────
    // runOnce — dispatch due fire
    // ─────────────────────────────────────────────────────────────

    public function test_run_once_dispatches_due_fire(): void
    {
        $schedule = $this->makeDueSchedule('dispatch-due-fire', 'tenant-a');

        $daemon = $this->makeDaemon(schedules: [$schedule]);

        $held = $daemon->runOnce();

        self::assertTrue($held);
        self::assertSame(1, $this->enqueuer->count());
    }

    public function test_run_once_dispatches_fire_with_correct_tenant_id(): void
    {
        $schedule = $this->makeDueSchedule('tenant-fire', 'tenant-xyz');

        $daemon = $this->makeDaemon(schedules: [$schedule]);
        $daemon->runOnce();

        $firedSlots = $this->enqueuer->firedSlots();
        self::assertCount(1, $firedSlots);
        self::assertSame('tenant-xyz', $firedSlots[0]->tenantId);
    }

    public function test_run_once_dispatches_fire_with_correct_schedule_id(): void
    {
        $schedule = $this->makeDueSchedule('schedule-id-fire', 'tenant-a');

        $daemon = $this->makeDaemon(schedules: [$schedule]);
        $daemon->runOnce();

        $firedSlots = $this->enqueuer->firedSlots();
        self::assertCount(1, $firedSlots);
        self::assertSame($schedule->id->toString(), $firedSlots[0]->scheduleId->toString());
    }

    public function test_run_once_dispatches_multiple_due_schedules(): void
    {
        $schedules = [
            $this->makeDueSchedule('multi-a', 'tenant-a'),
            $this->makeDueSchedule('multi-b', 'tenant-a'),
            $this->makeDueSchedule('multi-c', 'tenant-a'),
        ];

        $daemon = $this->makeDaemon(schedules: $schedules);
        $daemon->runOnce();

        self::assertSame(3, $this->enqueuer->count());
    }

    // ─────────────────────────────────────────────────────────────
    // runOnce — idempotency (second cycle same slot)
    // ─────────────────────────────────────────────────────────────

    public function test_second_run_once_does_not_re_enqueue_already_dispatched_slot(): void
    {
        $schedule = $this->makeDueSchedule('idempotent-slot', 'tenant-a');

        $daemon = $this->makeDaemon(schedules: [$schedule]);

        $daemon->runOnce(); // First cycle: dispatches fire
        $this->enqueuer->reset();

        $daemon->runOnce(); // Second cycle: slot already in ledger → AlreadyDispatched

        self::assertSame(0, $this->enqueuer->count(), 'Second runOnce must not re-enqueue an already-dispatched slot');
    }

    public function test_idempotency_preserved_across_multiple_cycles(): void
    {
        $schedule = $this->makeDueSchedule('idempotent-multi', 'tenant-a');

        $daemon = $this->makeDaemon(schedules: [$schedule]);

        $daemon->runOnce();
        $this->enqueuer->reset();
        $daemon->runOnce();
        $this->enqueuer->reset();
        $daemon->runOnce();

        self::assertSame(0, $this->enqueuer->count(), 'Third runOnce must still be idempotent');
    }

    // ─────────────────────────────────────────────────────────────
    // runOnce — returns false when shard blocked
    // ─────────────────────────────────────────────────────────────

    public function test_run_once_returns_false_when_shard_held_by_another_daemon(): void
    {
        $leaseStore = new InMemoryLeaseStore($this->clock);
        $schedule   = $this->makeDueSchedule('blocked-shard', 'tenant-a');

        $daemonA = $this->makeDaemon(schedules: [$schedule], leasePort: $leaseStore);
        $daemonB = $this->makeDaemon(schedules: [$schedule], leasePort: $leaseStore);

        // Daemon A acquires shard 0.
        self::assertTrue($daemonA->runOnce());

        // Daemon B cannot acquire the same shard — must return false.
        self::assertFalse($daemonB->runOnce());
    }

    public function test_run_once_returns_false_dispatches_nothing_when_blocked(): void
    {
        $leaseStore = new InMemoryLeaseStore($this->clock);
        $schedule   = $this->makeDueSchedule('blocked-no-dispatch', 'tenant-a');

        $daemonA = $this->makeDaemon(schedules: [$schedule], leasePort: $leaseStore);
        $daemonB = $this->makeDaemon(schedules: [$schedule], leasePort: $leaseStore);

        $daemonA->runOnce();
        $this->enqueuer->reset();
        $daemonB->runOnce();

        self::assertSame(0, $this->enqueuer->count());
    }

    // ─────────────────────────────────────────────────────────────
    // runOnce — FireDispatchException caught; daemon continues
    // ─────────────────────────────────────────────────────────────

    public function test_fire_dispatch_exception_caught_daemon_continues_to_next_fire(): void
    {
        $failCount  = 0;
        $successFires = [];

        // Custom enqueuer: fails on the very first call, succeeds on all subsequent.
        $failOnceEnqueuer = new class($failCount, $successFires) implements SchedulerEnqueuerPort {
            public function __construct(
                private int   &$failCount,
                private array &$successFires,
            ) {}

            public function enqueue(ScheduledFire $fire, Schedule $schedule): void
            {
                if ($this->failCount === 0) {
                    $this->failCount++;
                    throw new \RuntimeException('Simulated infrastructure failure on first fire');
                }
                $this->successFires[] = $fire;
            }
        };

        $schedules = [
            $this->makeDueSchedule('fail-once-a', 'tenant-a'),
            $this->makeDueSchedule('fail-once-b', 'tenant-a'),
        ];

        $dispatcher = new FireDispatcher(
            runStore:          $this->runStore,
            enqueuer:          $failOnceEnqueuer,
            connection:        $this->connection,
            clock:             $this->clock,
            assumedDoneTtlSec: 3600,
        );

        $daemon = $this->makeDaemon(
            schedules:   $schedules,
            dispatcher:  $dispatcher,
        );

        // Must not throw — FireDispatchException is caught per-fire.
        $daemon->runOnce();

        self::assertSame(1, $failCount, 'Enqueuer must have been called at least once (first fails)');
        self::assertCount(1, $successFires, 'Second fire must have succeeded after first failed');
    }

    // ─────────────────────────────────────────────────────────────
    // stop()
    // ─────────────────────────────────────────────────────────────

    public function test_stop_before_run_once_does_not_throw(): void
    {
        $daemon = $this->makeDaemon();

        $daemon->stop(); // stop before any work

        // runOnce() still runs normally — it does not check $running.
        self::assertTrue($daemon->runOnce());
    }

    public function test_stop_is_idempotent(): void
    {
        $daemon = $this->makeDaemon();

        $daemon->stop();
        $daemon->stop();
        $daemon->stop();

        // No exception thrown.
        self::assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure helpers
    // ─────────────────────────────────────────────────────────────

    /** @param list<Schedule> $schedules */
    private function makeDaemon(
        array                $schedules              = [],
        int                  $shardCount             = 1,
        int                  $leaseTtlSec            = 30,
        int                  $maxIdleSec             = 60,
        int                  $tenantMaxConcurrentFires = 0,
        ?LeasePort           $leasePort              = null,
        ?FireDispatcher      $dispatcher             = null,
    ): SchedulerDaemon {
        $clock      = $this->clock;
        $leasePort ??= new InMemoryLeaseStore($clock);

        $slotCalc   = new SlotCalculator();
        $resolver   = new MisfireResolver($slotCalc);
        $dueScan    = new DueScan($resolver, 86400);

        $dispatcher ??= new FireDispatcher(
            runStore:          $this->runStore,
            enqueuer:          $this->enqueuer,
            connection:        $this->connection,
            clock:             $clock,
            assumedDoneTtlSec: 3600,
        );

        $scheduleStore    = $this->makeScheduleStore($schedules);
        $scheduleResolver = new ScheduleResolver(new StaticScheduleRegistry(), $scheduleStore);

        // Seed each schedule's cadence cursor 1h before the clock so their due (now−30s) one-shot
        // slots fall inside the (cursor, now] window. Under the cursor model a never-scanned
        // schedule anchors to `now` and would otherwise do no catch-up.
        $seedAt = $clock->now()->modify('-3600 seconds');
        foreach ($schedules as $s) {
            $this->cursorStore->advance($s->id, $s->tenantId, $seedAt, 0);
        }

        return new SchedulerDaemon(
            leasePort:               $leasePort,
            scheduleResolver:        $scheduleResolver,
            cursorStore:             $this->cursorStore,
            dueScan:                 $dueScan,
            fireDispatcher:          $dispatcher,
            clock:                   $clock,
            logger:                  new NullLogger(),
            shardCount:              $shardCount,
            leaseTtlSec:             $leaseTtlSec,
            maxIdleSec:              $maxIdleSec,
            tenantMaxConcurrentFires: $tenantMaxConcurrentFires,
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

    private function makeDueSchedule(string $name, ?string $tenantId): Schedule
    {
        $id   = ScheduleId::generate();
        $now  = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $fire = $now->modify('-30 seconds');

        // Track for cleanup.
        $this->createdIds[] = $id;

        return new Schedule(
            id:       $id,
            name:     $name,
            source:   ScheduleSource::Static,
            trigger:  new OneShotTrigger($fire),
            command:  new CommandSpec('Vortos\Scheduler\Tests\Integration\FakeCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: $tenantId,
        );
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

        $c = self::CURSORS_TABLE;
        $this->connection->executeStatement("
            CREATE TABLE IF NOT EXISTS {$c} (
                schedule_id    VARCHAR(36)  NOT NULL,
                tenant_id      VARCHAR(255) NULL,
                cursor_at      TIMESTAMPTZ  NOT NULL,
                cursor_version INTEGER      NOT NULL DEFAULT 1,
                updated_at     TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_{$c} PRIMARY KEY (schedule_id)
            )
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
            $this->connection->executeStatement(
                "DELETE FROM " . self::CURSORS_TABLE . " WHERE schedule_id IN ({$placeholders})",
                $ids,
            );
        } catch (Throwable) {
        }
    }
}
