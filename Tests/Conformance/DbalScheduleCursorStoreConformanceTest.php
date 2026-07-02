<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Throwable;
use Vortos\Scheduler\Store\Dbal\DbalScheduleCursorStore;
use Vortos\Scheduler\Store\ScheduleCursorStoreInterface;
use Vortos\Scheduler\Testing\ScheduleCursorStoreConformanceTestCase;

/**
 * Runs the full cursor-store conformance suite against a live PostgreSQL instance.
 *
 * Run inside the backend Docker container:
 *   docker compose exec backend php vendor/bin/phpunit \
 *     packages/Vortos/src/Scheduler/Tests/Conformance/DbalScheduleCursorStoreConformanceTest.php
 */
final class DbalScheduleCursorStoreConformanceTest extends ScheduleCursorStoreConformanceTestCase
{
    private const TABLE = 'vortos_scheduler_cursors';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->ensureTable();
    }

    protected function createStore(): ScheduleCursorStoreInterface
    {
        return new DbalScheduleCursorStore($this->connection, self::TABLE);
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
                schedule_id    VARCHAR(36)  NOT NULL,
                tenant_id      VARCHAR(255) NULL,
                cursor_at      TIMESTAMPTZ  NOT NULL,
                cursor_version INTEGER      NOT NULL DEFAULT 1,
                updated_at     TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_{$t} PRIMARY KEY (schedule_id)
            )
        ");
    }
}
