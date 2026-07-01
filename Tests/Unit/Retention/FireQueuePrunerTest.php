<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Retention;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Retention\FireQueuePruner;
use Vortos\Scheduler\Testing\RecordingSchedulerMetrics;

final class FireQueuePrunerTest extends TestCase
{
    private const TABLE = 'vortos_scheduler_fire_queue';

    private Connection   $connection;
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('
            CREATE TABLE ' . self::TABLE . ' (
                id TEXT NOT NULL PRIMARY KEY,
                run_id TEXT NOT NULL,
                schedule_id TEXT NOT NULL,
                tenant_id TEXT NULL,
                slot TEXT NOT NULL,
                scheduled_for DATETIME NOT NULL,
                command_class TEXT NOT NULL,
                command_payload TEXT NOT NULL,
                metadata TEXT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                created_at DATETIME NOT NULL,
                dispatched_at DATETIME NULL,
                failure_reason TEXT NULL
            )
        ');

        // "Now" is fixed at 2026-07-01 12:00:00.
        $this->clock = new MutableClock(new DateTimeImmutable('2026-07-01 12:00:00'));
    }

    public function test_disabled_when_retention_days_is_zero(): void
    {
        $this->insert('old', 'dispatched', '2026-01-01 00:00:00');

        self::assertSame(0, $this->makePruner(0)->prune());
        self::assertSame(1, $this->rowCount());
    }

    public function test_prunes_only_terminal_rows_older_than_cutoff(): void
    {
        // 7-day cutoff → anything with dispatched_at < 2026-06-24 12:00:00 goes.
        $this->insert('old-dispatched', 'dispatched', '2026-06-01 00:00:00'); // prune
        $this->insert('old-failed',     'failed',     '2026-06-10 00:00:00'); // prune
        $this->insert('recent',         'dispatched', '2026-06-30 00:00:00'); // keep (within window)
        $this->insert('pending',        'pending',    null);                  // keep (not terminal)
        $this->insert('processing',     'processing', null);                  // keep (not terminal)

        $deleted = $this->makePruner(7)->prune();

        self::assertSame(2, $deleted);
        self::assertSame(3, $this->rowCount());
        self::assertSame(
            ['pending', 'processing', 'recent'],
            $this->remainingIds(),
        );
    }

    public function test_boundary_row_exactly_at_cutoff_is_kept(): void
    {
        // Cutoff is strict `<`, so a row dispatched exactly at the cutoff survives.
        $this->insert('at-cutoff', 'dispatched', '2026-06-24 12:00:00');

        self::assertSame(0, $this->makePruner(7)->prune());
        self::assertSame(1, $this->rowCount());
    }

    public function test_records_metric_for_pruned_rows(): void
    {
        $this->insert('old-1', 'dispatched', '2026-06-01 00:00:00');
        $this->insert('old-2', 'failed',     '2026-06-01 00:00:00');
        $recording = new RecordingSchedulerMetrics();

        $this->makePruner(7, metrics: $recording->schedulerMetrics)->prune();

        $recording->assertCounterIncremented('vortos_scheduler_fire_queue_pruned_total');
        $this->addToAssertionCount(1);
    }

    public function test_records_no_metric_when_nothing_pruned(): void
    {
        $this->insert('recent', 'dispatched', '2026-06-30 00:00:00');
        $recording = new RecordingSchedulerMetrics();

        $this->makePruner(7, metrics: $recording->schedulerMetrics)->prune();

        $recording->assertNothingEmitted();
        $this->addToAssertionCount(1);
    }

    public function test_prunes_across_multiple_batches(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insert("row-{$i}", 'dispatched', '2026-06-01 00:00:00');
        }

        // batchSize 2 forces three delete chunks (2 + 2 + 1).
        $deleted = $this->makePruner(7, batchSize: 2)->prune();

        self::assertSame(5, $deleted);
        self::assertSame(0, $this->rowCount());
    }

    private function makePruner(
        int $retentionDays,
        int $batchSize = 5000,
        ?\Vortos\Scheduler\Observability\SchedulerMetrics $metrics = null,
    ): FireQueuePruner {
        return new FireQueuePruner(
            connection:     $this->connection,
            clock:          $this->clock,
            retentionDays:  $retentionDays,
            table:          self::TABLE,
            batchSize:      $batchSize,
            maxDurationSec: 240,
            metrics:        $metrics,
        );
    }

    private function insert(string $id, string $status, ?string $dispatchedAt): void
    {
        $this->connection->insert(self::TABLE, [
            'id'              => $id,
            'run_id'          => 'run-' . $id,
            'schedule_id'     => 'sched-1',
            'tenant_id'       => null,
            'slot'            => 'slot-' . $id,
            'scheduled_for'   => '2026-06-01 00:00:00',
            'command_class'   => 'Fixture',
            'command_payload' => '{}',
            'metadata'        => null,
            'status'          => $status,
            'created_at'      => '2026-06-01 00:00:00',
            'dispatched_at'   => $dispatchedAt,
            'failure_reason'  => null,
        ]);
    }

    private function rowCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE);
    }

    /** @return list<string> */
    private function remainingIds(): array
    {
        return $this->connection->fetchFirstColumn('SELECT id FROM ' . self::TABLE . ' ORDER BY id ASC');
    }
}
