<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Store\Dbal\DbalScheduleStore;
use Vortos\Scheduler\Store\Dbal\ScheduleSerializer;

/**
 * Verifies the tenant-isolation contract across both store implementations.
 *
 * Core invariant: data from tenant A must be invisible to tenant B and to
 * system-scope queries (and vice versa). A daemon-mode query (null tenantId
 * on findAllActive / findLastSlots) MUST see all rows.
 */
final class TenantIsolationIntegrationTest extends TestCase
{
    private const SCHED_TABLE = 'vortos_scheduler_schedules';
    private const RUNS_TABLE  = 'vortos_scheduler_runs';

    private Connection          $connection;
    private DbalScheduleStore   $scheduleStore;
    private DbalScheduleRunStore $runStore;

    protected function setUp(): void
    {
        $this->connection    = $this->connectOrSkip();
        $this->scheduleStore = new DbalScheduleStore($this->connection, new ScheduleSerializer(), self::SCHED_TABLE);
        $this->runStore      = new DbalScheduleRunStore($this->connection, self::RUNS_TABLE);
        $this->ensureTables();
        $this->cleanTestRows();
    }

    protected function tearDown(): void
    {
        $this->cleanTestRows();
    }

    // ─────────────────────────────────────────────────────────────
    // ScheduleStore: cross-tenant isolation
    // ─────────────────────────────────────────────────────────────

    public function test_tenant_a_schedule_invisible_to_tenant_b(): void
    {
        $id = ScheduleId::generate();
        $this->scheduleStore->save($this->makeSchedule($id, 'isol-name-a', 'tenant-a'));

        // Tenant-B scoped find must return null
        self::assertNull($this->scheduleStore->find($id, 'tenant-b'));
    }

    public function test_tenant_a_schedule_invisible_to_system_scope(): void
    {
        $id = ScheduleId::generate();
        $this->scheduleStore->save($this->makeSchedule($id, 'isol-name-b', 'tenant-a'));

        self::assertNull($this->scheduleStore->find($id, null));
    }

    public function test_system_schedule_invisible_to_tenant(): void
    {
        $id = ScheduleId::generate();
        $this->scheduleStore->save($this->makeSchedule($id, 'isol-name-c', null));

        self::assertNull($this->scheduleStore->find($id, 'tenant-b'));
    }

    public function test_find_active_respects_tenant_scope(): void
    {
        $idA = ScheduleId::generate();
        $idB = ScheduleId::generate();
        $this->scheduleStore->save($this->makeSchedule($idA, 'isol-active-a', 'tenant-a'));
        $this->scheduleStore->save($this->makeSchedule($idB, 'isol-active-b', 'tenant-b'));

        $tenantAItems = iterator_to_array($this->scheduleStore->findActive('tenant-a'));
        $ids          = array_map(fn(Schedule $s) => $s->id->toString(), $tenantAItems);

        self::assertContains($idA->toString(), $ids);
        self::assertNotContains($idB->toString(), $ids);
    }

    public function test_find_all_active_daemon_mode_sees_all_tenants(): void
    {
        $idA = ScheduleId::generate();
        $idB = ScheduleId::generate();
        $this->scheduleStore->save($this->makeSchedule($idA, 'isol-daemon-a', 'tenant-a'));
        $this->scheduleStore->save($this->makeSchedule($idB, 'isol-daemon-b', 'tenant-b'));

        $allItems = iterator_to_array($this->scheduleStore->findAllActive());
        $ids      = array_map(fn(Schedule $s) => $s->id->toString(), $allItems);

        self::assertContains($idA->toString(), $ids);
        self::assertContains($idB->toString(), $ids);
    }

    // ─────────────────────────────────────────────────────────────
    // ScheduleRunStore: cross-tenant isolation
    // ─────────────────────────────────────────────────────────────

    public function test_run_from_tenant_a_invisible_to_tenant_b_via_find_run_state(): void
    {
        $schedId = ScheduleId::generate();
        $runA    = $this->makeRun($schedId, 'tenant-a', 'isol-run-slot');
        $this->runStore->insertRun($runA);

        // Tenant-B should see null, not tenant-A's run
        self::assertNull($this->runStore->findRunState($schedId, 'isol-run-slot', 'tenant-b'));
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure helpers
    // ─────────────────────────────────────────────────────────────

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
            $this->markTestSkipped('Postgres not reachable: ' . $e->getMessage());
        }
    }

    private function ensureTables(): void
    {
        $s = self::SCHED_TABLE;
        $r = self::RUNS_TABLE;

        $this->connection->executeStatement("
            CREATE TABLE IF NOT EXISTS {$s} (
                id              VARCHAR(36)   NOT NULL,
                name            VARCHAR(255)  NOT NULL,
                tenant_id       VARCHAR(255)  NULL,
                source          VARCHAR(10)   NOT NULL,
                status          VARCHAR(10)   NOT NULL,
                trigger_type    VARCHAR(20)   NOT NULL,
                trigger_data    TEXT          NOT NULL,
                command_class   VARCHAR(512)  NOT NULL,
                command_payload TEXT          NOT NULL,
                misfire_policy  TEXT          NOT NULL,
                overlap_policy  VARCHAR(20)   NOT NULL,
                timezone        VARCHAR(100)  NOT NULL,
                jitter_seconds  INT           NULL,
                sensitive       BOOLEAN       NOT NULL DEFAULT FALSE,
                metadata        TEXT          NOT NULL DEFAULT '{}',
                version         INT           NOT NULL DEFAULT 1,
                created_at      TIMESTAMPTZ   NOT NULL,
                updated_at      TIMESTAMPTZ   NOT NULL,
                CONSTRAINT pk_{$s} PRIMARY KEY (id)
            )
        ");
        $this->connection->executeStatement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_{$s}_tenant_name
                ON {$s} (tenant_id, name)
                WHERE tenant_id IS NOT NULL
        ");
        $this->connection->executeStatement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_{$s}_system_name
                ON {$s} (name)
                WHERE tenant_id IS NULL
        ");

        $this->connection->executeStatement("
            CREATE TABLE IF NOT EXISTS {$r} (
                run_id        CHAR(64)     NOT NULL,
                schedule_id   VARCHAR(36)  NOT NULL,
                tenant_id     VARCHAR(255) NULL,
                slot          TEXT         NOT NULL,
                scheduled_for TIMESTAMPTZ  NOT NULL,
                dispatched_at TIMESTAMPTZ  NOT NULL,
                completed_at  TIMESTAMPTZ  NULL,
                run_state     VARCHAR(20)  NOT NULL DEFAULT 'dispatched',
                attempt       SMALLINT     NOT NULL DEFAULT 1,
                CONSTRAINT pk_{$r} PRIMARY KEY (run_id)
            )
        ");
        $this->connection->executeStatement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_{$r}_slot
                ON {$r} (tenant_id, schedule_id, slot)
        ");
    }

    private function cleanTestRows(): void
    {
        try {
            $this->connection->executeStatement(
                "DELETE FROM " . self::SCHED_TABLE . " WHERE name LIKE 'isol-%'"
            );
            $this->connection->executeStatement(
                "DELETE FROM " . self::RUNS_TABLE . " WHERE slot LIKE 'isol-%'"
            );
        } catch (Throwable) {
        }
    }

    private function makeSchedule(ScheduleId $id, string $name, ?string $tenantId): Schedule
    {
        return new Schedule(
            id:       $id,
            name:     $name,
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('App\\Command\\IsolationTest'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: $tenantId,
        );
    }

    private function makeRun(ScheduleId $scheduleId, string $tenantId, string $slot): ScheduleRun
    {
        $key = IdempotencyKey::fromSlotKey($scheduleId->toString() . ':' . $slot . ':' . $tenantId);
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');

        return new ScheduleRun(
            runId:        $key->value,
            scheduleId:   $scheduleId,
            tenantId:     $tenantId,
            slot:         $slot,
            scheduledFor: $now,
            dispatchedAt: $now,
            state:        RunState::Dispatched,
        );
    }
}
