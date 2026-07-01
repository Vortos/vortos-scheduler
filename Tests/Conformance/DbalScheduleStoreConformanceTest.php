<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Throwable;
use Vortos\Scheduler\Store\Dbal\DbalScheduleStore;
use Vortos\Scheduler\Store\Dbal\ScheduleSerializer;
use Vortos\Scheduler\Store\ScheduleStoreInterface;
use Vortos\Scheduler\Testing\ScheduleStoreConformanceTestCase;

/**
 * Runs the full ScheduleStoreConformanceTestCase against a live PostgreSQL instance.
 *
 * Run inside the backend Docker container:
 *   docker compose exec backend php vendor/bin/phpunit \
 *     packages/Vortos/src/Scheduler/Tests/Conformance/DbalScheduleStoreConformanceTest.php
 */
final class DbalScheduleStoreConformanceTest extends ScheduleStoreConformanceTestCase
{
    private const TABLE = 'vortos_scheduler_schedules';

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

    protected function createStore(): ScheduleStoreInterface
    {
        return new DbalScheduleStore($this->connection, new ScheduleSerializer(), self::TABLE);
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

        // Unique constraint for (tenant_id, name) for non-null tenants
        $this->connection->executeStatement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_{$t}_tenant_name
                ON {$t} (tenant_id, name)
                WHERE tenant_id IS NOT NULL
        ");

        // Partial unique index for system-scope names (tenant_id IS NULL)
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
                "DELETE FROM " . self::TABLE . " WHERE name LIKE '%-a' OR name LIKE '%-b'
                  OR name IN (
                    'crud-new','crud-version','crud-update','crud-delete',
                    'by-name-lookup','round-trip-fields','system-schedule',
                    'dup-name','shared-name','sys-dup','cross-scope',
                    'isolation-find','sys-invisible','tenant-invisible','del-isolation',
                    'all-a','all-b','fab-system','fab-tenant-a','fab-tenant-b',
                    'fa-active','fa-paused','fa-disabled',
                    'fall-active','fall-paused','fall-disabled',
                    'opt-lock','opt-seq','ghost-schedule'
                  )"
            );
        } catch (Throwable) {
            // Ignore — table may not exist yet on first setUp
        }
    }
}
