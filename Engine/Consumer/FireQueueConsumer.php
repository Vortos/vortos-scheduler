<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine\Consumer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Scheduler\Fire\CommandHydrator;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Observability\SchedulerMetricsPort;
use Vortos\Scheduler\Observability\SchedulerTracer;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Drains `vortos_scheduler_fire_queue`, dispatching each row's (command_class,
 * command_payload) through the CQRS CommandBus and transitioning the fire-ledger
 * row accordingly.
 *
 * This is the piece that was missing end to end: FireDispatcher (S4) only ever
 * writes intent to this table; nothing previously read it back out, so no
 * scheduled command — static or dynamic — actually ran. See
 * SCHEDULER_AUTO_PRUNE_IMPL_PLAN.md, "Prerequisite 2 — Fire Queue Consumer (S12)"
 * for the full trace.
 *
 * Claim strategy is portable across Postgres and SQLite:
 *  - Postgres: SELECT ... FOR UPDATE SKIP LOCKED inside a short transaction, so
 *    more than one consumer process can run concurrently without double-claiming
 *    a row (same reasoning PostgresAdvisoryLeaseStore already relies on).
 *  - SQLite: plain UPDATE ... WHERE id IN (subquery) — single-writer, no lock
 *    contention to guard against.
 *
 * One row's failure never blocks the batch: each row is processed and committed
 * independently, inside its own try/catch.
 */
final class FireQueueConsumer
{
    public function __construct(
        private readonly Connection                 $connection,
        private readonly ScheduleRunStoreInterface  $runStore,
        private readonly CommandBusInterface        $commandBus,
        private readonly CommandHydrator            $hydrator,
        private readonly ClockInterface             $clock,
        private readonly SchedulerTracer            $tracer,
        private readonly ?SchedulerMetricsPort      $metrics = null,
        private readonly LoggerInterface            $logger = new NullLogger(),
        private readonly string                     $table = 'vortos_scheduler_fire_queue',
    ) {}

    /**
     * Claim and process up to $batchSize pending rows. Returns the number processed
     * (successes + failures — both count as "processed", since both terminate the row).
     */
    public function consumeBatch(int $batchSize): int
    {
        $rows = $this->claimBatch($batchSize);

        foreach ($rows as $row) {
            $this->processRow($row);
        }

        return count($rows);
    }

    /** @return list<array<string, mixed>> */
    private function claimBatch(int $batchSize): array
    {
        $isPostgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        if ($isPostgres) {
            $ids = $this->claimBatchPostgres($batchSize);
        } else {
            $ids = $this->claimBatchPortable($batchSize);
        }

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->table} WHERE id IN ({$placeholders})",
            $ids,
        );
    }

    /** @return list<string> */
    private function claimBatchPostgres(int $batchSize): array
    {
        $limit = max(0, (int) $batchSize);
        $this->connection->beginTransaction();

        try {
            $ids = $this->connection->fetchFirstColumn(
                "SELECT id FROM {$this->table}
                 WHERE status = 'pending'
                 ORDER BY created_at ASC
                 LIMIT {$limit}
                 FOR UPDATE SKIP LOCKED",
            );

            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $this->connection->executeStatement(
                    "UPDATE {$this->table} SET status = 'processing' WHERE id IN ({$placeholders})",
                    $ids,
                );
            }

            $this->connection->commit();

            return $ids;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /** @return list<string> */
    private function claimBatchPortable(int $batchSize): array
    {
        $limit = max(0, (int) $batchSize);
        $ids   = $this->connection->fetchFirstColumn(
            "SELECT id FROM {$this->table}
             WHERE status = 'pending'
             ORDER BY created_at ASC
             LIMIT {$limit}",
        );

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->connection->executeStatement(
            "UPDATE {$this->table} SET status = 'processing' WHERE id IN ({$placeholders})",
            $ids,
        );

        return $ids;
    }

    /** @param array<string, mixed> $row */
    private function processRow(array $row): void
    {
        $rowId        = (string) $row['id'];
        $runId        = (string) $row['run_id'];
        $scheduleId   = (string) $row['schedule_id'];
        $tenantId     = isset($row['tenant_id']) && $row['tenant_id'] !== null && $row['tenant_id'] !== ''
            ? (string) $row['tenant_id']
            : null;
        $slot         = (string) $row['slot'];
        $commandClass = (string) $row['command_class'];
        $now          = $this->clock->now();

        try {
            $payload = json_decode((string) $row['command_payload'], true, 512, \JSON_THROW_ON_ERROR);

            $this->tracer->traceConsume($scheduleId, $slot, $tenantId, function () use ($commandClass, $payload) {
                $command = $this->hydrator->hydrate($commandClass, $payload);

                return $this->commandBus->dispatch($command);
            });

            $this->runStore->transitionRunState($runId, RunState::Completed, $now);
            $this->markRow($rowId, 'dispatched', $now, null);
            $this->metrics?->recordConsumeResult(true, $scheduleId, $tenantId);
        } catch (\Throwable $e) {
            $this->logger->error('Scheduler fire-queue consume failed', [
                'run_id'        => $runId,
                'schedule_id'   => $scheduleId,
                'command_class' => $commandClass,
                'error'         => $e->getMessage(),
            ]);

            try {
                $this->runStore->transitionRunState($runId, RunState::Failed, $now);
            } catch (\Throwable) {
                // Already terminal (e.g. a concurrent retry got there first) — do not
                // mask the original failure below with a state-transition error.
            }

            $this->markRow($rowId, 'failed', $now, substr($e->getMessage(), 0, 2000));
            $this->metrics?->recordConsumeResult(false, $scheduleId, $tenantId);
        }
    }

    private function markRow(string $rowId, string $status, \DateTimeImmutable $at, ?string $failureReason): void
    {
        $this->connection->executeStatement(
            "UPDATE {$this->table}
             SET status = ?, dispatched_at = ?, failure_reason = ?
             WHERE id = ?",
            [$status, $at->format('Y-m-d H:i:s'), $failureReason, $rowId],
        );
    }
}
