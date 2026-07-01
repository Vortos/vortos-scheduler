<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Store\Dbal\DbalScheduleStore;
use Vortos\Scheduler\Store\Dbal\ScheduleSerializer;
use Vortos\Scheduler\Store\Exception\OptimisticLockException;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;

/**
 * Verifies the optimistic-concurrency invariant in DbalScheduleStore.
 *
 * The version column implements a CAS (compare-and-swap) guard:
 *   INSERT  → version = 1
 *   UPDATE  → WHERE version = ? AND sets version = version + 1
 * A stale writer (wrong version) gets 0 affected rows → OptimisticLockException.
 */
final class OptimisticLockIntegrationTest extends TestCase
{
    private const TABLE = 'vortos_scheduler_schedules';

    private Connection        $connection;
    private DbalScheduleStore $store;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->store      = new DbalScheduleStore($this->connection, new ScheduleSerializer(), self::TABLE);
        $this->ensureTable();
        $this->cleanTestRows();
    }

    protected function tearDown(): void
    {
        $this->cleanTestRows();
    }

    // ─────────────────────────────────────────────────────────────
    // Tests
    // ─────────────────────────────────────────────────────────────

    public function test_version_increments_on_each_successful_update(): void
    {
        $id       = ScheduleId::generate();
        $schedule = $this->makeSchedule($id, 'opt-lock-incr');

        // INSERT → version becomes 1 in DB
        $this->store->save($schedule);

        // Reload; version should be 1
        $loaded = $this->store->find($id, null);
        self::assertNotNull($loaded);
        self::assertSame(1, $loaded->version);

        // UPDATE with version=1 → DB version becomes 2
        $this->store->save($loaded);

        $reloaded = $this->store->find($id, null);
        self::assertNotNull($reloaded);
        self::assertSame(2, $reloaded->version);
    }

    public function test_stale_version_throws_optimistic_lock_exception(): void
    {
        $id       = ScheduleId::generate();
        $schedule = $this->makeSchedule($id, 'opt-lock-stale');

        // Writer 1 inserts, then updates → DB version = 2
        $this->store->save($schedule);
        $v1 = $this->store->find($id, null);
        $this->store->save($v1);  // advances DB to version=2

        // Writer 2 holds old version=1 object and tries to update
        $this->expectException(OptimisticLockException::class);
        $this->store->save($v1);  // $v1 still has version=1 — stale
    }

    public function test_save_non_existent_id_with_version_gt_0_throws_not_found(): void
    {
        // Simulate a writer that has version=1 for a row that was deleted
        $ghost = $this->makeSchedule(ScheduleId::generate(), 'ghost-opt-lock');
        // Give it a fake version > 0 so it goes via UPDATE path
        $ghostPersisted = new Schedule(
            id:       $ghost->id,
            name:     $ghost->name,
            source:   $ghost->source,
            trigger:  $ghost->trigger,
            command:  $ghost->command,
            misfire:  $ghost->misfire,
            overlap:  $ghost->overlap,
            timezone: $ghost->timezone,
            jitter:   $ghost->jitter,
            status:   $ghost->status,
            tenantId: $ghost->tenantId,
            version:  1,   // pretend it was persisted
        );

        $this->expectException(ScheduleNotFoundException::class);
        $this->store->save($ghostPersisted);
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

    private function ensureTable(): void
    {
        $t = self::TABLE;
        $this->connection->executeStatement("
            CREATE TABLE IF NOT EXISTS {$t} (
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
                CONSTRAINT pk_{$t} PRIMARY KEY (id)
            )
        ");
        $this->connection->executeStatement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_{$t}_tenant_name
                ON {$t} (tenant_id, name)
                WHERE tenant_id IS NOT NULL
        ");
        $this->connection->executeStatement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_{$t}_system_name
                ON {$t} (name)
                WHERE tenant_id IS NULL
        ");
    }

    private function cleanTestRows(): void
    {
        try {
            $this->connection->executeStatement(
                "DELETE FROM " . self::TABLE . " WHERE name LIKE 'opt-lock-%' OR name = 'ghost-opt-lock'"
            );
        } catch (Throwable) {
        }
    }

    private function makeSchedule(ScheduleId $id, string $name): Schedule
    {
        return new Schedule(
            id:       $id,
            name:     $name,
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('App\\Command\\OptLockTest'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}
