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
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduledFire;
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
 * Integration tests for OverlapPolicy::Skip semantics against real PostgreSQL.
 *
 * Verifies:
 *  1. SkipOverlap fires nothing when a prior run is still Dispatched and within TTL.
 *  2. SkipOverlap allows through when the prior run completed.
 *  3. SkipOverlap allows through when the prior run's TTL has expired (assumed-done watermark).
 *  4. AllowConcurrent always allows through regardless of prior state.
 *  5. Queue policy (defers to overlap queue, not yet implemented in S4 — skipped).
 */
final class OverlapControlIntegrationTest extends TestCase
{
    private const RUNS_TABLE = 'vortos_scheduler_runs';
    private const TTL        = 3600;

    private Connection                $connection;
    private DbalScheduleRunStore      $runStore;
    private RecordingSchedulerEnqueuer $enqueuer;
    private SlotCalculator            $slotCalc;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->runStore   = new DbalScheduleRunStore($this->connection, self::RUNS_TABLE);
        $this->enqueuer   = new RecordingSchedulerEnqueuer();
        $this->slotCalc   = new SlotCalculator();
        $this->ensureTables();
        $this->cleanTestRows();
    }

    protected function tearDown(): void
    {
        $this->cleanTestRows();
    }

    // ─────────────────────────────────────────────────────────────
    // OverlapPolicy::Skip — in-flight prior run blocks new dispatch
    // ─────────────────────────────────────────────────────────────

    public function test_skip_overlap_blocks_dispatch_when_prior_run_in_flight(): void
    {
        $schedule = $this->makeSchedule(OverlapPolicy::Skip);
        $now      = new DateTimeImmutable('2026-07-01T10:00:00Z');

        // Dispatch slot 09:00 — prior run now in-flight (Dispatched state)
        $fire1    = $this->makeFire($schedule, new DateTimeImmutable('2026-07-01T09:00:00Z'));
        $result1  = $this->makeDispatcher($schedule, $now)->dispatch($fire1, $schedule);
        self::assertSame(FireDispatchResult::Dispatched, $result1);

        // Try to dispatch slot 10:00 — prior run still Dispatched → must skip
        $fire2   = $this->makeFire($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $result2 = $this->makeDispatcher($schedule, $now->modify('+1 second'))->dispatch($fire2, $schedule);

        self::assertSame(FireDispatchResult::SkippedOverlap, $result2);
        self::assertSame(1, $this->enqueuer->count(), 'Second fire must not be enqueued');
    }

    public function test_skip_overlap_allows_dispatch_when_prior_run_completed(): void
    {
        $schedule = $this->makeSchedule(OverlapPolicy::Skip);
        $now      = new DateTimeImmutable('2026-07-01T10:00:00Z');

        // Dispatch prior slot and immediately complete it
        $fire1 = $this->makeFire($schedule, new DateTimeImmutable('2026-07-01T09:00:00Z'));
        $this->makeDispatcher($schedule, $now)->dispatch($fire1, $schedule);
        $this->runStore->transitionRunState(
            \Vortos\Scheduler\Fire\IdempotencyKey::fromSlotKey($fire1->slot)->value,
            RunState::Completed,
            $now,
        );

        // Dispatch next slot — prior run Completed → should succeed
        $fire2   = $this->makeFire($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $result2 = $this->makeDispatcher($schedule, $now->modify('+1 second'))->dispatch($fire2, $schedule);

        self::assertSame(FireDispatchResult::Dispatched, $result2);
    }

    public function test_skip_overlap_allows_dispatch_when_prior_run_failed(): void
    {
        $schedule = $this->makeSchedule(OverlapPolicy::Skip);
        $now      = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $fire1 = $this->makeFire($schedule, new DateTimeImmutable('2026-07-01T09:00:00Z'));
        $this->makeDispatcher($schedule, $now)->dispatch($fire1, $schedule);
        $this->runStore->transitionRunState(
            \Vortos\Scheduler\Fire\IdempotencyKey::fromSlotKey($fire1->slot)->value,
            RunState::Failed,
            $now,
        );

        $fire2   = $this->makeFire($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $result2 = $this->makeDispatcher($schedule, $now->modify('+1 second'))->dispatch($fire2, $schedule);

        self::assertSame(FireDispatchResult::Dispatched, $result2);
    }

    public function test_skip_overlap_allows_dispatch_when_prior_run_ttl_expired(): void
    {
        $schedule = $this->makeSchedule(OverlapPolicy::Skip);
        $dispatchTime = new DateTimeImmutable('2026-07-01T08:00:00Z');

        // Dispatch prior slot at 08:00
        $fire1 = $this->makeFire($schedule, new DateTimeImmutable('2026-07-01T08:00:00Z'));
        $this->makeDispatcher($schedule, $dispatchTime)->dispatch($fire1, $schedule);
        // Do NOT complete it — simulate a crash

        // Now it's 3601 seconds later (TTL + 1 second) → assume done
        $nowAfterTtl = $dispatchTime->modify('+' . (self::TTL + 1) . ' seconds');

        $fire2   = $this->makeFire($schedule, new DateTimeImmutable('2026-07-01T09:00:00Z'));
        $result2 = $this->makeDispatcher($schedule, $nowAfterTtl)->dispatch($fire2, $schedule);

        self::assertSame(FireDispatchResult::Dispatched, $result2, 'TTL watermark must allow through after expiry');
    }

    // ─────────────────────────────────────────────────────────────
    // OverlapPolicy::AllowConcurrent — always dispatches
    // ─────────────────────────────────────────────────────────────

    public function test_allow_concurrent_dispatches_while_prior_run_in_flight(): void
    {
        $schedule = $this->makeSchedule(OverlapPolicy::AllowConcurrent);
        $now      = new DateTimeImmutable('2026-07-01T10:00:00Z');

        $fire1 = $this->makeFire($schedule, new DateTimeImmutable('2026-07-01T09:00:00Z'));
        $this->makeDispatcher($schedule, $now)->dispatch($fire1, $schedule);

        $fire2   = $this->makeFire($schedule, new DateTimeImmutable('2026-07-01T10:00:00Z'));
        $result2 = $this->makeDispatcher($schedule, $now->modify('+1 second'))->dispatch($fire2, $schedule);

        self::assertSame(FireDispatchResult::Dispatched, $result2);
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────

    private function makeDispatcher(Schedule $schedule, DateTimeImmutable $now): FireDispatcher
    {
        return new FireDispatcher(
            runStore:         $this->runStore,
            enqueuer:         $this->enqueuer,
            connection:       $this->connection,
            clock:            $this->fixedClock($now),
            assumedDoneTtlSec: self::TTL,
        );
    }

    private function makeFire(Schedule $schedule, DateTimeImmutable $scheduledFor): ScheduledFire
    {
        $slot = $this->slotCalc->slotKey($schedule->id, $scheduledFor, $schedule->timezone);

        return new ScheduledFire(
            scheduleId:   $schedule->id,
            tenantId:     $schedule->tenantId,
            slot:         $slot,
            scheduledFor: $scheduledFor,
        );
    }

    private function makeSchedule(OverlapPolicy $overlap): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'overlap-test',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('Vortos\Scheduler\Tests\Integration\FakeCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  $overlap,
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
                "DELETE FROM " . self::RUNS_TABLE . " WHERE run_state IN ('dispatched','completed','failed')"
                . " AND tenant_id = 'ta' AND slot LIKE '%-00:00+00:00'"
            );
        } catch (Throwable) {
        }
    }
}
