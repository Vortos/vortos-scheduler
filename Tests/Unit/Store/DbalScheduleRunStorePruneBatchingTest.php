<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Store;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\IdempotencyKey;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;

/**
 * pruneOldRuns() batching: proves the chunked-delete loop (a) deletes everything
 * eligible across multiple internal chunks when given a small batch size, and
 * (b) respects the wall-clock budget instead of running unbounded.
 *
 * The chunk-delete SQL (Store/Dbal/DbalScheduleRunStore::buildChunkDeleteQuery)
 * has no Postgres/SQLite branching, so SQLite in-memory exercises the exact same
 * code path as production — no Docker/Postgres required for this test.
 */
final class DbalScheduleRunStorePruneBatchingTest extends TestCase
{
    private const TABLE = 'vortos_scheduler_runs';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('
            CREATE TABLE ' . self::TABLE . ' (
                run_id        CHAR(64)     NOT NULL PRIMARY KEY,
                schedule_id   VARCHAR(36)  NOT NULL,
                tenant_id     VARCHAR(255) NULL,
                slot          TEXT         NOT NULL,
                scheduled_for DATETIME     NOT NULL,
                dispatched_at DATETIME     NOT NULL,
                completed_at  DATETIME     NULL,
                run_state     VARCHAR(20)  NOT NULL DEFAULT "dispatched",
                attempt       SMALLINT     NOT NULL DEFAULT 1
            )
        ');
    }

    public function test_deletes_full_backlog_across_multiple_chunks(): void
    {
        $store = new DbalScheduleRunStore($this->connection, self::TABLE, pruneBatchSize: 10, pruneMaxDurationSec: 60);
        $this->insertTerminalRuns(35);

        $result = $store->pruneOldRuns(new DateTimeImmutable('2099-01-01T00:00:00Z'));

        // 35 rows, batch size 10 => 4 DELETE statements internally (10,10,10,5), all consumed.
        self::assertSame(35, $result->deletedCount);
        self::assertFalse($result->truncated);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE));
    }

    public function test_respects_max_duration_budget_and_reports_partial_progress(): void
    {
        // Zero-second budget: the loop executes exactly one chunk (the deadline check
        // happens only after a chunk completes), then stops even though more remain.
        $store = new DbalScheduleRunStore($this->connection, self::TABLE, pruneBatchSize: 5, pruneMaxDurationSec: 0);
        $this->insertTerminalRuns(20);

        $result = $store->pruneOldRuns(new DateTimeImmutable('2099-01-01T00:00:00Z'));

        self::assertSame(5, $result->deletedCount);
        self::assertTrue($result->truncated);
        self::assertSame(15, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE));
    }

    public function test_single_chunk_when_backlog_smaller_than_batch_size(): void
    {
        $store = new DbalScheduleRunStore($this->connection, self::TABLE, pruneBatchSize: 100, pruneMaxDurationSec: 60);
        $this->insertTerminalRuns(3);

        $result = $store->pruneOldRuns(new DateTimeImmutable('2099-01-01T00:00:00Z'));

        self::assertSame(3, $result->deletedCount);
        self::assertFalse($result->truncated);
    }

    private function insertTerminalRuns(int $count): void
    {
        $scheduleId = ScheduleId::generate();
        $past       = new DateTimeImmutable('2020-01-01T00:00:00Z');

        for ($i = 0; $i < $count; $i++) {
            $slot  = 'batch-slot-' . $i;
            $runId = IdempotencyKey::fromSlotKey($scheduleId->toString() . ':' . $slot)->value;

            $run = new ScheduleRun(
                runId:        $runId,
                scheduleId:   $scheduleId,
                tenantId:     null,
                slot:         $slot,
                scheduledFor: $past,
                dispatchedAt: $past,
                state:        RunState::Dispatched,
            );

            $this->connection->insert(self::TABLE, [
                'run_id'        => $run->runId,
                'schedule_id'   => $run->scheduleId->toString(),
                'tenant_id'     => $run->tenantId,
                'slot'          => $run->slot,
                'scheduled_for' => $past->format('Y-m-d H:i:s'),
                'dispatched_at' => $past->format('Y-m-d H:i:s'),
                'run_state'     => RunState::Completed->value,
                'attempt'       => 1,
            ]);
        }
    }
}
