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
 * Claim strategy is portable across Postgres and SQLite:
 *  - Postgres: SELECT ... FOR UPDATE SKIP LOCKED inside a short transaction, so
 *    more than one consumer process can run concurrently without double-claiming
 *    a row.
 *  - SQLite: plain UPDATE ... WHERE id IN (subquery) — single-writer.
 *
 * ## R7-4 / SCHED-1 — capability-aware claim + requeue safety net
 *
 * A blue/green (heterogeneous-image) fleet runs consumers with DIFFERENT command sets. Previously
 * ANY running consumer drained the single shared queue, so a stale standby could grab a fire for a
 * newly-added command class its image lacks and hard-`failed` it. Two defences:
 *
 *  1. **Capability-aware claim.** When a capability resolver reports the classes this node can run,
 *     the claim query only selects fires whose `command_class` is in that set — a stale consumer
 *     structurally cannot claim a fire it can't run; it is left (SKIP LOCKED) for a capable node.
 *  2. **Requeue safety net.** If an unrunnable class is somehow claimed anyway (no allowlist
 *     configured, or the class was removed between claim and dispatch), the row is REQUEUED with a
 *     visibility-timeout backoff and a bounded attempt counter — never hard-failed — and only
 *     dead-lettered after `maxAttempts`. A genuine command failure (class present and capable, but
 *     the handler/payload throws) stays terminal `failed` as before: retrying a poison pill is
 *     pointless because a capable consumer would fail identically.
 */
final class FireQueueConsumer
{
    public function __construct(
        private readonly Connection                 $connection,
        private readonly ScheduleRunStoreInterface  $runStore,
        // Nullable, injected with NULL_ON_INVALID_REFERENCE: the CQRS CommandBus is optional and
        // its alias may be unwired in a minimal container. A null bus is a loud runtime error (see
        // consumeBatch), never a silently-vanishing command.
        private readonly ?CommandBusInterface       $commandBus,
        private readonly CommandHydrator            $hydrator,
        private readonly ClockInterface             $clock,
        private readonly SchedulerTracer            $tracer,
        private readonly ?SchedulerMetricsPort      $metrics = null,
        private readonly LoggerInterface            $logger = new NullLogger(),
        private readonly string                     $table = 'vortos_scheduler_fire_queue',
        // R7-4: null resolver ⇒ no capability filter (claim-all); the requeue net still applies.
        private readonly ?ConsumerCapabilityResolverInterface $capabilityResolver = null,
        private readonly int                        $maxAttempts = 10,
        private readonly int                        $backoffBaseSec = 2,
        private readonly int                        $backoffCapSec = 300,
    ) {}

    /**
     * Claim and process up to $batchSize pending rows. Returns the number processed
     * (successes + terminal failures + requeues — every row that was claimed).
     */
    public function consumeBatch(int $batchSize): int
    {
        // Fail loud BEFORE claiming — a null bus means nothing can be dispatched, so claiming would
        // strand rows in 'processing'.
        if ($this->commandBus === null) {
            throw new \RuntimeException(
                'Cannot consume the scheduler fire queue: no CQRS CommandBus is wired. Install '
                . 'vortos-cqrs and ensure its CommandBusInterface alias is registered in this '
                . 'container before running scheduler:consume.',
            );
        }

        $capabilities = $this->capabilityResolver?->capableCommandClasses();

        // A resolver that reports an empty capability set means this node can run nothing — claim
        // nothing rather than issue an `IN ()` that some drivers reject.
        if ($capabilities !== null && $capabilities === []) {
            return 0;
        }

        $rows = $this->claimBatch($batchSize, $capabilities);

        foreach ($rows as $row) {
            $this->processRow($row, $capabilities);
        }

        return count($rows);
    }

    /**
     * @param list<string>|null $capabilities
     * @return list<array<string, mixed>>
     */
    private function claimBatch(int $batchSize, ?array $capabilities): array
    {
        $isPostgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;

        $ids = $isPostgres
            ? $this->claimBatchPostgres($batchSize, $capabilities)
            : $this->claimBatchPortable($batchSize, $capabilities);

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->connection->fetchAllAssociative(
            "SELECT * FROM {$this->table} WHERE id IN ({$placeholders})",
            $ids,
        );
    }

    /**
     * Visible-pending predicate + optional capability filter, shared by both claim paths.
     *
     * @param list<string>|null $capabilities
     * @return array{0: string, 1: list<mixed>} [whereClause, bindings]
     */
    private function claimPredicate(?array $capabilities): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // available_at gates the visibility timeout: a requeued row is invisible until its backoff
        // elapses. NULL available_at (never requeued) is always visible.
        $where = "status = 'pending' AND (available_at IS NULL OR available_at <= ?)";
        $bindings = [$now];

        if ($capabilities !== null) {
            $placeholders = implode(',', array_fill(0, count($capabilities), '?'));
            $where .= " AND command_class IN ({$placeholders})";
            $bindings = array_merge($bindings, $capabilities);
        }

        return [$where, $bindings];
    }

    /**
     * @param list<string>|null $capabilities
     * @return list<string>
     */
    private function claimBatchPostgres(int $batchSize, ?array $capabilities): array
    {
        $limit = max(0, (int) $batchSize);
        [$where, $bindings] = $this->claimPredicate($capabilities);

        $this->connection->beginTransaction();

        try {
            $ids = $this->connection->fetchFirstColumn(
                "SELECT id FROM {$this->table}
                 WHERE {$where}
                 ORDER BY created_at ASC
                 LIMIT {$limit}
                 FOR UPDATE SKIP LOCKED",
                $bindings,
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

    /**
     * @param list<string>|null $capabilities
     * @return list<string>
     */
    private function claimBatchPortable(int $batchSize, ?array $capabilities): array
    {
        $limit = max(0, (int) $batchSize);
        [$where, $bindings] = $this->claimPredicate($capabilities);

        $ids = $this->connection->fetchFirstColumn(
            "SELECT id FROM {$this->table}
             WHERE {$where}
             ORDER BY created_at ASC
             LIMIT {$limit}",
            $bindings,
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

    /**
     * @param array<string, mixed> $row
     * @param list<string>|null $capabilities
     */
    private function processRow(array $row, ?array $capabilities): void
    {
        $rowId        = (string) $row['id'];
        $runId        = (string) $row['run_id'];
        $scheduleId   = (string) $row['schedule_id'];
        $tenantId     = isset($row['tenant_id']) && $row['tenant_id'] !== null && $row['tenant_id'] !== ''
            ? (string) $row['tenant_id']
            : null;
        $slot         = (string) $row['slot'];
        $commandClass = (string) $row['command_class'];
        $attempts     = (int) ($row['attempts'] ?? 0);
        $now          = $this->clock->now();

        // Belt-and-suspenders: even though the claim filtered by capability, re-check at dispatch
        // time (the class may have been removed since claim, or the node runs with no allowlist).
        $incapableReason = $this->incapableReason($commandClass, $capabilities);
        if ($incapableReason !== null) {
            $this->requeueOrDeadLetter($rowId, $runId, $scheduleId, $tenantId, $commandClass, $attempts, $incapableReason, $now);
            return;
        }

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
                // Already terminal — do not mask the original failure.
            }

            $this->markRow($rowId, 'failed', $now, substr($e->getMessage(), 0, 2000));
            $this->metrics?->recordConsumeResult(false, $scheduleId, $tenantId);
        }
    }

    /**
     * Why this node cannot run the command, or null if it can.
     *
     * @param list<string>|null $capabilities
     */
    private function incapableReason(string $commandClass, ?array $capabilities): ?string
    {
        if (!class_exists($commandClass)) {
            return 'unknown_class';
        }

        if ($capabilities !== null && !in_array($commandClass, $capabilities, true)) {
            return 'not_capable';
        }

        return null;
    }

    /**
     * Requeue an unrunnable fire with backoff, or dead-letter it once attempts are exhausted. The
     * run-ledger row is NOT transitioned to Failed on a requeue — a capable consumer will complete
     * it; only a dead-letter is terminal.
     */
    private function requeueOrDeadLetter(
        string $rowId,
        string $runId,
        string $scheduleId,
        ?string $tenantId,
        string $commandClass,
        int $attempts,
        string $reason,
        \DateTimeImmutable $now,
    ): void {
        $nextAttempts = $attempts + 1;

        if ($nextAttempts >= $this->maxAttempts) {
            $this->logger->error('Scheduler fire dead-lettered: no capable consumer', [
                'run_id'        => $runId,
                'schedule_id'   => $scheduleId,
                'command_class' => $commandClass,
                'reason'        => $reason,
                'attempts'      => $nextAttempts,
            ]);

            try {
                $this->runStore->transitionRunState($runId, RunState::Failed, $now);
            } catch (\Throwable) {
            }

            $this->connection->executeStatement(
                "UPDATE {$this->table}
                 SET status = 'dead_letter', attempts = ?, dispatched_at = ?, failure_reason = ?, last_error = ?
                 WHERE id = ?",
                [
                    $nextAttempts,
                    $now->format('Y-m-d H:i:s'),
                    sprintf('dead-lettered after %d attempts (%s): %s', $nextAttempts, $reason, $commandClass),
                    $reason,
                    $rowId,
                ],
            );

            $this->metrics?->recordFireDeadLettered($reason, $scheduleId, $tenantId);

            return;
        }

        $availableAt = $now->modify(sprintf('+%d seconds', $this->backoffSeconds($nextAttempts)));

        $this->logger->warning('Scheduler fire requeued for a capable consumer', [
            'run_id'        => $runId,
            'schedule_id'   => $scheduleId,
            'command_class' => $commandClass,
            'reason'        => $reason,
            'attempts'      => $nextAttempts,
            'available_at'  => $availableAt->format('Y-m-d H:i:s'),
        ]);

        $this->connection->executeStatement(
            "UPDATE {$this->table}
             SET status = 'pending', attempts = ?, available_at = ?, last_error = ?, dispatched_at = NULL
             WHERE id = ?",
            [
                $nextAttempts,
                $availableAt->format('Y-m-d H:i:s'),
                sprintf('%s: %s', $reason, $commandClass),
                $rowId,
            ],
        );

        $this->metrics?->recordFireRequeued($reason, $scheduleId, $tenantId);
    }

    /** Exponential backoff with jitter, capped. */
    private function backoffSeconds(int $attempts): int
    {
        $base = (int) min($this->backoffCapSec, $this->backoffBaseSec ** min($attempts, 16));
        $base = max(1, $base);
        // Small deterministic-enough jitter (0..25% of base) to avoid a thundering herd of retries.
        $jitter = random_int(0, (int) max(1, $base / 4));

        return min($this->backoffCapSec, $base + $jitter);
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
