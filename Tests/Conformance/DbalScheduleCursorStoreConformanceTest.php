<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Throwable;
use Vortos\Scheduler\Schedule\ScheduleId;
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

    /**
     * A cursor row predating the first_seen_at column must become judgeable the next time it is
     * advanced — the scenario the column's migration describes, and the one production is in: 17 of
     * 27 live cursors carry NULL there.
     *
     * NULL is the truthful value for those rows, so a migration must not invent one; the only honest
     * place to fill it is the next write. Until that happened the overdue check could reach no
     * verdict on them at all — not dead, not new, permanently unknown.
     */
    public function test_advance_backfills_a_null_first_seen_from_before_the_column_existed(): void
    {
        $store = $this->createStore();
        $id    = ScheduleId::generate();
        $t     = self::TABLE;

        // A pre-column row, written the way one would have been: no first_seen_at.
        $this->connection->executeStatement(
            "INSERT INTO {$t} (schedule_id, tenant_id, cursor_at, cursor_version, updated_at, first_seen_at)
             VALUES (?, ?, ?, 1, ?, NULL)",
            [$id->toString(), 'ta', '2026-07-01 10:00:00', '2026-07-01 10:00:00'],
        );

        self::assertNull(
            $store->findCursors([$id], null)[$id->toString()]->firstSeenAt,
            'Precondition: the row starts unjudgeable.',
        );

        self::assertTrue($store->advance($id, 'ta', new DateTimeImmutable('2026-07-01T11:00:00Z'), 1));

        self::assertNotNull(
            $store->findCursors([$id], null)[$id->toString()]->firstSeenAt,
            'Advancing a pre-column cursor must record a first-seen instant, or it stays unjudgeable forever.',
        );
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
                -- Mirrors the real migration. This was absent for a while, so the suite would have
                -- passed against a fresh database while every first_seen_at assertion silently
                -- exercised nothing — the column only existed where the table already did.
                first_seen_at  TIMESTAMPTZ  NULL,
                CONSTRAINT pk_{$t} PRIMARY KEY (schedule_id)
            )
        ");

        // Test tables created before the column exists would otherwise keep missing it, since
        // CREATE TABLE IF NOT EXISTS is a no-op against them.
        $this->connection->executeStatement("ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS first_seen_at TIMESTAMPTZ NULL");
    }
}
