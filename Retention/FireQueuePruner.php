<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Retention;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Observability\SchedulerMetricsPort;

/**
 * Prunes terminal (dispatched/failed) rows from `vortos_scheduler_fire_queue`.
 *
 * Since S12, FireQueueConsumer marks processed rows `dispatched`/`failed` instead
 * of deleting them (so their outcome stays inspectable), which means the queue
 * grows unbounded exactly like `vortos_scheduler_runs` did before auto-prune.
 * The fire-queue is transient dispatch intent, though — the durable record of what
 * ran lives in `vortos_scheduler_runs` and `vortos_scheduler_audit_log` — so these
 * rows can be pruned on a much shorter horizon than run history.
 *
 * Invoked from RunRetentionSweeper's daily sweep (and thus also the manual,
 * policy-aware `scheduler:prune`), so no second scheduler is introduced. Pending /
 * processing rows are never touched — only terminal ones past the cutoff.
 *
 * The batched-delete + wall-clock-budget loop mirrors DbalScheduleRunStore::
 * pruneOldRuns exactly, including the portable `id IN (SELECT ... LIMIT N)` shape
 * (Postgres and SQLite both lack a portable `DELETE ... LIMIT`).
 */
final class FireQueuePruner
{
    public function __construct(
        private readonly Connection      $connection,
        private readonly ClockPort       $clock,
        private readonly int             $retentionDays,
        private readonly string          $table,
        private readonly int             $batchSize = 5000,
        private readonly int             $maxDurationSec = 240,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?SchedulerMetricsPort $metrics = null,
    ) {}

    /** @return int number of rows deleted */
    public function prune(): int
    {
        if ($this->retentionDays <= 0) {
            return 0; // disabled
        }

        // Match FireQueueConsumer::markRow's write convention exactly: it stores
        // dispatched_at as clock->now()->format('Y-m-d H:i:s') with no timezone
        // conversion. Both share this ClockPort, so an un-converted cutoff compares
        // apples-to-apples regardless of the process default timezone.
        $cutoff = $this->clock->now()
            ->modify(sprintf('-%d days', $this->retentionDays))
            ->format('Y-m-d H:i:s');

        $deadline     = microtime(true) + max(0, $this->maxDurationSec);
        $batchSize    = max(1, $this->batchSize);
        $totalDeleted = 0;

        while (true) {
            $affected      = $this->deleteChunk($cutoff, $batchSize);
            $totalDeleted += $affected;

            if ($affected < $batchSize) {
                break; // drained — fewer than a full batch means nothing eligible remains
            }

            if (microtime(true) >= $deadline) {
                $this->logger->info('Fire-queue prune budget exhausted; more rows may remain', [
                    'deleted' => $totalDeleted,
                    'cutoff'  => $cutoff,
                ]);
                break;
            }
        }

        if ($totalDeleted > 0) {
            $this->logger->info('Pruned terminal fire-queue rows', [
                'deleted' => $totalDeleted,
                'cutoff'  => $cutoff,
            ]);
        }

        $this->metrics?->recordFireQueuePruned($totalDeleted);

        return $totalDeleted;
    }

    private function deleteChunk(string $cutoff, int $limit): int
    {
        $sql = "DELETE FROM {$this->table} WHERE id IN (
            SELECT id FROM {$this->table}
            WHERE status IN ('dispatched', 'failed')
              AND dispatched_at < ?
            ORDER BY dispatched_at ASC
            LIMIT {$limit}
        )";

        return (int) $this->connection->executeStatement($sql, [$cutoff]);
    }
}
