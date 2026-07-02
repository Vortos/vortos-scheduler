<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Throwable;
use Vortos\Scheduler\Engine\DueScan;
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Testing\RecordingSchedulerEnqueuer;

/**
 * Integration tests for FireDispatcher against a real PostgreSQL database.
 *
 * Verifies exactly-once-effect, idempotent dispatch, and rollback on failure.
 * Uses RecordingSchedulerEnqueuer so the fire queue table is not required.
 */
final class FireDispatcherIdempotencyTest extends TestCase
{
    private const RUNS_TABLE = 'vortos_scheduler_runs';

    private Connection                $connection;
    private DbalScheduleRunStore      $runStore;
    private RecordingSchedulerEnqueuer $enqueuer;
    private FireDispatcher            $dispatcher;
    private SlotCalculator            $slotCalc;
    private Schedule                  $schedule;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->runStore   = new DbalScheduleRunStore($this->connection, self::RUNS_TABLE);
        $this->enqueuer   = new RecordingSchedulerEnqueuer();
        $this->slotCalc   = new SlotCalculator();
        $this->dispatcher = new FireDispatcher(
            runStore:         $this->runStore,
            enqueuer:         $this->enqueuer,
            connection:       $this->connection,
            clock:            $this->fixedClock(new DateTimeImmutable('2026-07-01T10:00:00Z')),
            assumedDoneTtlSec: 3600,
        );
        $this->schedule = $this->makeSchedule();

        $this->ensureTables();
        $this->cleanTestRows();
    }

    protected function tearDown(): void
    {
        $this->cleanTestRows();
    }

    // ─────────────────────────────────────────────────────────────
    // Exactly-once dispatch
    // ─────────────────────────────────────────────────────────────

    public function test_first_dispatch_returns_dispatched(): void
    {
        $fire   = $this->makeFire(new DateTimeImmutable('2026-07-01T09:00:00Z'));
        $result = $this->dispatcher->dispatch($fire, $this->schedule);

        self::assertSame(FireDispatchResult::Dispatched, $result);
        self::assertSame(1, $this->enqueuer->count());
    }

    public function test_duplicate_dispatch_returns_already_dispatched(): void
    {
        $fire = $this->makeFire(new DateTimeImmutable('2026-07-01T09:00:00Z'));

        $first  = $this->dispatcher->dispatch($fire, $this->schedule);
        $second = $this->dispatcher->dispatch($fire, $this->schedule);

        self::assertSame(FireDispatchResult::Dispatched,        $first);
        self::assertSame(FireDispatchResult::AlreadyDispatched, $second);
        // Enqueuer must only have received one call — second was caught by UNIQUE constraint
        self::assertSame(1, $this->enqueuer->count());
    }

    public function test_duplicate_slot_does_not_corrupt_db_state(): void
    {
        $fire = $this->makeFire(new DateTimeImmutable('2026-07-01T09:30:00Z'));

        $this->dispatcher->dispatch($fire, $this->schedule);
        $this->dispatcher->dispatch($fire, $this->schedule);

        // Connection must be clean — no lingering aborted transaction
        $row = $this->connection->fetchAssociative(
            "SELECT run_id FROM " . self::RUNS_TABLE . " WHERE slot = ?",
            [$fire->slot],
        );
        self::assertNotFalse($row, 'Run row must exist after idempotent duplicate');
    }

    public function test_two_distinct_slots_both_dispatched(): void
    {
        $fire1 = $this->makeFire(new DateTimeImmutable('2026-07-01T08:00:00Z'));
        $fire2 = $this->makeFire(new DateTimeImmutable('2026-07-01T09:00:00Z'));

        self::assertSame(FireDispatchResult::Dispatched, $this->dispatcher->dispatch($fire1, $this->schedule));
        self::assertSame(FireDispatchResult::Dispatched, $this->dispatcher->dispatch($fire2, $this->schedule));

        self::assertSame(2, $this->enqueuer->count());
    }

    // ─────────────────────────────────────────────────────────────
    // Enqueuer failure — rollback
    // ─────────────────────────────────────────────────────────────

    public function test_enqueuer_failure_rolls_back_ledger_insert(): void
    {
        $this->enqueuer->failOnNext('Simulated fire queue write failure');
        $fire = $this->makeFire(new DateTimeImmutable('2026-07-01T07:00:00Z'));

        $this->expectException(\Vortos\Scheduler\Engine\Exception\FireDispatchException::class);

        $this->dispatcher->dispatch($fire, $this->schedule);
    }

    public function test_enqueuer_failure_leaves_no_ledger_row(): void
    {
        $this->enqueuer->failOnNext();
        $fire = $this->makeFire(new DateTimeImmutable('2026-07-01T06:00:00Z'));

        try {
            $this->dispatcher->dispatch($fire, $this->schedule);
        } catch (\Throwable) {
            // expected
        }

        // Ledger row must NOT exist — atomic rollback
        $state = $this->runStore->findRunState($fire->scheduleId, $fire->slot, 'ta');
        self::assertNull($state, 'Ledger must be clean after enqueuer failure rollback');
    }

    // ─────────────────────────────────────────────────────────────
    // DueScan + FireDispatcher integration smoke
    // ─────────────────────────────────────────────────────────────

    public function test_due_scan_feeds_dispatcher_correctly(): void
    {
        $resolver   = new MisfireResolver($this->slotCalc);
        $scan       = new DueScan($resolver, 86400);
        $now        = new DateTimeImmutable('2026-07-01T11:00:01Z');
        $cursor     = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $scanResult = $scan->compute(
            [$this->schedule],
            [$this->schedule->id->toString() => $cursor],
            $now,
        );

        self::assertCount(1, $scanResult->fires);

        $dispatchResult = $this->dispatcher->dispatch($scanResult->fires[0], $this->schedule);

        self::assertSame(FireDispatchResult::Dispatched, $dispatchResult);
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────

    private function makeFire(\DateTimeImmutable $scheduledFor): \Vortos\Scheduler\Fire\ScheduledFire
    {
        $slot = $this->slotCalc->slotKey(
            $this->schedule->id,
            $scheduledFor,
            $this->schedule->timezone,
        );

        return new \Vortos\Scheduler\Fire\ScheduledFire(
            scheduleId:   $this->schedule->id,
            tenantId:     'ta',
            slot:         $slot,
            scheduledFor: $scheduledFor,
        );
    }

    private function makeSchedule(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'dispatcher-idempotency-test',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('Vortos\Scheduler\Tests\Integration\FakeCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: 'ta',
        );
    }

    private function fixedClock(DateTimeImmutable $at): ClockInterface
    {
        return new class($at) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}
            public function now(): DateTimeImmutable { return $this->now; }
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

    private function cleanTestRows(): void
    {
        try {
            $this->connection->executeStatement(
                "DELETE FROM " . self::RUNS_TABLE . " WHERE schedule_id = ?",
                [$this->schedule?->id->toString() ?? '00000000-0000-0000-0000-000000000000'],
            );
        } catch (Throwable) {
        }
    }
}
