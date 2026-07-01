<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\Exception\DuplicateSlotException;
use Vortos\Scheduler\Store\Exception\InvalidRunStateTransitionException;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * DBAL driver for ScheduleRunStoreInterface.
 *
 * insertRun() deliberately has NO internal transaction boundary.
 * FireDispatcher (S4) wraps insertRun() + outbox-write in one BEGIN…COMMIT so
 * that both writes are atomic. See DuplicateSlotException docblock for the
 * post-exception rollback contract.
 */
final class DbalScheduleRunStore implements ScheduleRunStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string     $table = 'vortos_scheduler_runs',
    ) {}

    public function insertRun(ScheduleRun $run): void
    {
        try {
            $this->connection->insert($this->table, [
                'run_id'        => $run->runId,
                'schedule_id'   => $run->scheduleId->toString(),
                'tenant_id'     => $run->tenantId,
                'slot'          => $run->slot,
                'scheduled_for' => $this->utc($run->scheduledFor),
                'dispatched_at' => $this->utc($run->dispatchedAt),
                'run_state'     => $run->state->value,
                'attempt'       => $run->attempt,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw new DuplicateSlotException($run->slot, $run->scheduleId, $e);
        }
    }

    public function findLastSlots(array $scheduleIds, ?string $tenantId): array
    {
        if ($scheduleIds === []) {
            return [];
        }

        $ids          = array_map(static fn (ScheduleId $id) => $id->toString(), $scheduleIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($tenantId !== null) {
            $sql    = "SELECT DISTINCT ON (schedule_id) schedule_id, slot
                       FROM {$this->table}
                       WHERE schedule_id IN ({$placeholders})
                         AND tenant_id = ?
                       ORDER BY schedule_id, dispatched_at DESC";
            $params = [...$ids, $tenantId];
        } else {
            $sql    = "SELECT DISTINCT ON (schedule_id) schedule_id, slot
                       FROM {$this->table}
                       WHERE schedule_id IN ({$placeholders})
                       ORDER BY schedule_id, dispatched_at DESC";
            $params = $ids;
        }

        $rows   = $this->connection->fetchAllAssociative($sql, $params);
        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row['schedule_id']] = (string) $row['slot'];
        }

        return $result;
    }

    public function findRunState(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?RunState
    {
        if ($tenantId !== null) {
            $row = $this->connection->fetchAssociative(
                "SELECT run_state FROM {$this->table}
                 WHERE schedule_id = ? AND slot = ? AND tenant_id = ?",
                [$scheduleId->toString(), $slot, $tenantId],
            );
        } else {
            $row = $this->connection->fetchAssociative(
                "SELECT run_state FROM {$this->table}
                 WHERE schedule_id = ? AND slot = ?",
                [$scheduleId->toString(), $slot],
            );
        }

        return $row !== false ? RunState::from((string) $row['run_state']) : null;
    }

    public function transitionRunState(string $runId, RunState $newState, DateTimeImmutable $at): void
    {
        // Read current state first to enforce state-machine invariants.
        $current = $this->fetchRunStateByRunId($runId);

        if ($current === null) {
            throw new \RuntimeException(
                "ScheduleRunStore: run '{$runId}' not found in the ledger. " .
                'Cannot transition state — this is a programming error.',
            );
        }

        if (!$current->canTransitionTo($newState)) {
            throw new InvalidRunStateTransitionException($runId, $current, $newState);
        }

        $this->connection->executeStatement(
            "UPDATE {$this->table}
             SET run_state    = ?,
                 completed_at = ?
             WHERE run_id = ?",
            [
                $newState->value,
                $this->utc($at),
                $runId,
            ],
        );
    }

    public function findRunBySlot(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?ScheduleRun
    {
        if ($tenantId !== null) {
            $row = $this->connection->fetchAssociative(
                "SELECT run_id, schedule_id, tenant_id, slot, scheduled_for, dispatched_at, run_state, attempt
                 FROM {$this->table}
                 WHERE schedule_id = ? AND slot = ? AND tenant_id = ?",
                [$scheduleId->toString(), $slot, $tenantId],
            );
        } else {
            $row = $this->connection->fetchAssociative(
                "SELECT run_id, schedule_id, tenant_id, slot, scheduled_for, dispatched_at, run_state, attempt
                 FROM {$this->table}
                 WHERE schedule_id = ? AND slot = ?",
                [$scheduleId->toString(), $slot],
            );
        }

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function pruneOldRuns(DateTimeImmutable $before): int
    {
        return (int) $this->connection->executeStatement(
            "DELETE FROM {$this->table}
             WHERE dispatched_at < ?
               AND run_state IN (?, ?)",
            [
                $this->utc($before),
                RunState::Completed->value,
                RunState::Failed->value,
            ],
        );
    }

    public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array
    {
        if ($scheduleIds === []) {
            return [];
        }

        $ids          = array_map(static fn (ScheduleId $id) => $id->toString(), $scheduleIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if ($tenantId !== null) {
            $sql    = "SELECT schedule_id, MAX(dispatched_at) AS last_dispatched_at
                       FROM {$this->table}
                       WHERE schedule_id IN ({$placeholders})
                         AND tenant_id = ?
                       GROUP BY schedule_id";
            $params = [...$ids, $tenantId];
        } else {
            $sql    = "SELECT schedule_id, MAX(dispatched_at) AS last_dispatched_at
                       FROM {$this->table}
                       WHERE schedule_id IN ({$placeholders})
                       GROUP BY schedule_id";
            $params = $ids;
        }

        $rows   = $this->connection->fetchAllAssociative($sql, $params);
        $result = [];

        // Pre-fill with null for all requested IDs (never-fired schedules)
        foreach ($ids as $id) {
            $result[$id] = null;
        }

        foreach ($rows as $row) {
            $result[(string) $row['schedule_id']] = new DateTimeImmutable(
                (string) $row['last_dispatched_at'],
            );
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ScheduleRun
    {
        return new ScheduleRun(
            runId:        (string) $row['run_id'],
            scheduleId:   ScheduleId::fromString((string) $row['schedule_id']),
            tenantId:     isset($row['tenant_id']) && $row['tenant_id'] !== '' ? (string) $row['tenant_id'] : null,
            slot:         (string) $row['slot'],
            scheduledFor: new DateTimeImmutable((string) $row['scheduled_for']),
            dispatchedAt: new DateTimeImmutable((string) $row['dispatched_at']),
            state:        RunState::from((string) $row['run_state']),
            attempt:      (int) $row['attempt'],
        );
    }

    private function fetchRunStateByRunId(string $runId): ?RunState
    {
        $row = $this->connection->fetchAssociative(
            "SELECT run_state FROM {$this->table} WHERE run_id = ?",
            [$runId],
        );

        return $row !== false ? RunState::from((string) $row['run_state']) : null;
    }

    private function utc(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
