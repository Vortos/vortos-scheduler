<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Throwable;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Testing\ScheduleRunStoreConformanceTestCase;

/**
 * Runs the full ScheduleRunStoreConformanceTestCase against a live PostgreSQL instance.
 *
 * Run inside the backend Docker container:
 *   docker compose exec backend php vendor/bin/phpunit \
 *     packages/Vortos/src/Scheduler/Tests/Conformance/DbalScheduleRunStoreConformanceTest.php
 */
final class DbalScheduleRunStoreConformanceTest extends ScheduleRunStoreConformanceTestCase
{
    private const TABLE = 'vortos_scheduler_runs';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->ensureTable();
        $this->cleanTestRows();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanTestRows();
    }

    protected function createStore(): ScheduleRunStoreInterface
    {
        return new DbalScheduleRunStore($this->connection, self::TABLE);
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
            $this->markTestSkipped('Postgres not reachable: ' . $e->getMessage());
        }
    }

    private function ensureTable(): void
    {
        $t = self::TABLE;

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

        // The idempotency anchor
        $this->connection->executeStatement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_{$t}_slot
                ON {$t} (tenant_id, schedule_id, slot)
        ");

        $this->connection->executeStatement("
            CREATE INDEX IF NOT EXISTS idx_{$t}_schedule_state
                ON {$t} (schedule_id, run_state)
        ");

        $this->connection->executeStatement("
            CREATE INDEX IF NOT EXISTS idx_{$t}_schedule_dispatched
                ON {$t} (schedule_id, dispatched_at DESC)
        ");
    }

    private function cleanTestRows(): void
    {
        try {
            // Slot values used in tests are: s1, dup-slot, exc-slot, slot-x/y, same-slot,
            // tenant-slot, slot-a/b, state-slot, iso-state, trans-*, prune-*, daemon-slot
            $this->connection->executeStatement(
                "DELETE FROM " . self::TABLE . " WHERE slot LIKE 's%'
                  OR slot LIKE 'slot-%'
                  OR slot LIKE 'dup-%'
                  OR slot LIKE 'exc-%'
                  OR slot LIKE 'trans-%'
                  OR slot LIKE 'prune-%'
                  OR slot LIKE 'same-%'
                  OR slot LIKE 'tenant-%'
                  OR slot LIKE 'iso-%'
                  OR slot LIKE 'state-%'
                  OR slot LIKE 'daemon-%'
                  OR slot LIKE 'default-%'"
            );
        } catch (Throwable) {
        }
    }
}
