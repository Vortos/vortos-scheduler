<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Store\Exception\DuplicateSlotException;

/**
 * Verifies the atomic-enqueue seam: insertRun() is transaction-aware but does NOT
 * open its own transaction, enabling the caller (FireDispatcher) to wrap
 * insertRun() + outbox-write in a single BEGIN…COMMIT.
 *
 * These tests simulate the FireDispatcher's usage pattern directly.
 */
final class AtomicEnqueueIntegrationTest extends TestCase
{
    private const TABLE = 'vortos_scheduler_runs';

    private Connection       $connection;
    private DbalScheduleRunStore $store;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->store      = new DbalScheduleRunStore($this->connection, self::TABLE);
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

    public function test_insert_run_without_transaction_lands_in_db(): void
    {
        $run = $this->makeRun('atomic-no-tx');

        $this->store->insertRun($run);

        $state = $this->store->findRunState($run->scheduleId, $run->slot, 'ta');
        self::assertSame(RunState::Dispatched, $state);
    }

    public function test_duplicate_slot_throws_duplicate_slot_exception(): void
    {
        $run = $this->makeRun('atomic-dup');
        $this->store->insertRun($run);

        $this->expectException(DuplicateSlotException::class);

        $this->store->insertRun($run);
    }

    public function test_connection_is_clean_after_duplicate_and_explicit_rollback(): void
    {
        // First insert
        $run1 = $this->makeRun('atomic-clean-1');
        $this->store->insertRun($run1);

        // Open a transaction
        $this->connection->beginTransaction();

        try {
            // Duplicate — will throw
            $this->store->insertRun($run1);
            self::fail('Expected DuplicateSlotException');
        } catch (DuplicateSlotException) {
            // PostgreSQL aborts the tx on constraint violation; must rollBack
            $this->connection->rollBack();
        }

        // Connection must be usable again — insert a different slot
        $run2 = $this->makeRun('atomic-clean-2');
        $this->store->insertRun($run2);

        self::assertSame(RunState::Dispatched, $this->store->findRunState($run2->scheduleId, $run2->slot, 'ta'));
    }

    public function test_insert_within_explicit_transaction_commits_atomically(): void
    {
        $run = $this->makeRun('atomic-tx-commit');

        $this->connection->beginTransaction();
        $this->store->insertRun($run);
        $this->connection->commit();

        self::assertSame(RunState::Dispatched, $this->store->findRunState($run->scheduleId, $run->slot, 'ta'));
    }

    public function test_insert_within_explicit_transaction_rollback_leaves_no_row(): void
    {
        $run = $this->makeRun('atomic-tx-rollback');

        $this->connection->beginTransaction();
        $this->store->insertRun($run);
        $this->connection->rollBack();

        // Row must not be visible after rollback
        self::assertNull($this->store->findRunState($run->scheduleId, $run->slot, 'ta'));
    }

    public function test_duplicate_within_transaction_rolls_back_cleanly(): void
    {
        $run = $this->makeRun('atomic-dup-tx');
        $this->store->insertRun($run);  // first insert — committed

        $this->connection->beginTransaction();
        try {
            $this->store->insertRun($run); // duplicate
            self::fail('Expected DuplicateSlotException');
        } catch (DuplicateSlotException) {
            $this->connection->rollBack();
        }

        // The original row is intact
        self::assertSame(RunState::Dispatched, $this->store->findRunState($run->scheduleId, $run->slot, 'ta'));
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
    }

    private function cleanTestRows(): void
    {
        try {
            $this->connection->executeStatement(
                "DELETE FROM " . self::TABLE . " WHERE slot LIKE 'atomic-%'"
            );
        } catch (Throwable) {
        }
    }

    private function makeRun(string $slotSuffix): ScheduleRun
    {
        $id  = ScheduleId::generate();
        $key = IdempotencyKey::fromSlotKey($id->toString() . ':' . $slotSuffix);
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');

        return new ScheduleRun(
            runId:        $key->value,
            scheduleId:   $id,
            tenantId:     'ta',
            slot:         $slotSuffix,
            scheduledFor: $now,
            dispatchedAt: $now,
            state:        RunState::Dispatched,
        );
    }
}
