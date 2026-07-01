<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store;

use DateTimeImmutable;
use Vortos\Scheduler\Fire\RunState;
use Vortos\Scheduler\Fire\ScheduleRun;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\Exception\DuplicateSlotException;
use Vortos\Scheduler\Store\Exception\InvalidRunStateTransitionException;

/**
 * PORT: fire-ledger for the scheduler's exactly-once-effect guarantee.
 *
 * The UNIQUE constraint on (tenant_id, schedule_id, slot) in the underlying table
 * is the idempotency anchor. insertRun() failing with DuplicateSlotException means
 * "already dispatched" — the FireDispatcher (S4) catches it and skips re-enqueue.
 */
interface ScheduleRunStoreInterface
{
    /**
     * Insert a new run record into the fire-ledger.
     *
     * DOES NOT open its own transaction. The caller (FireDispatcher, S4) is responsible
     * for wrapping this + the outbox write in a single BEGIN…COMMIT to achieve atomic
     * exactly-once semantics. If called outside a transaction the single INSERT is
     * autocommit at the DB level.
     *
     * After a DuplicateSlotException, the active transaction (if any) is in an errored
     * state in PostgreSQL. The caller MUST rollBack() before issuing further statements.
     * This behaviour is documented on DuplicateSlotException and tested in
     * AtomicEnqueueIntegrationTest.
     *
     * @throws DuplicateSlotException if (tenantId, scheduleId, slot) already exists
     */
    public function insertRun(ScheduleRun $run): void;

    /**
     * Bulk-fetch the last dispatched slot key per schedule. Single query for N schedules.
     *
     *   Returns map<scheduleId-string => lastSlotKey-string>
     *   Missing entries mean "this schedule has never fired".
     *
     * Tenant scope:
     *   tenantId = null  → daemon mode; no tenant filter (runs for all tenants visible)
     *   tenantId = 'abc' → only runs for tenant 'abc' are considered
     *
     * Used by DueScan (S4) to compute which slots are now due.
     *
     * @param  list<ScheduleId> $scheduleIds
     * @return array<string, string>
     */
    public function findLastSlots(array $scheduleIds, ?string $tenantId): array;

    /**
     * Return the current run-state for a specific (scheduleId, slot) pair, or null if
     * no run record exists for that slot.
     *
     * Used by the overlap check in DueScan (S4): if the prior slot is still 'dispatched',
     * the SkipOverlap policy drops the incoming slot.
     *
     * Tenant scope: same rules as findLastSlots().
     */
    public function findRunState(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?RunState;

    /**
     * Transition a run's state (dispatched → completed|failed).
     *
     * Called by FireQueueConsumer (S12) after the CQRS CommandBus::dispatch() call
     * returns (success) or throws (failure).
     *
     * State machine is enforced: terminal states (completed, failed) cannot be
     * transitioned again. Non-existent runId throws \RuntimeException (programming error,
     * not a domain exception).
     *
     * @throws InvalidRunStateTransitionException if the transition is not allowed
     * @throws \RuntimeException                  if runId does not exist in the ledger
     */
    public function transitionRunState(
        string           $runId,
        RunState         $newState,
        DateTimeImmutable $at,
    ): void;

    /**
     * Return the full ScheduleRun record for a specific (scheduleId, slot) pair,
     * or null if no run exists for that slot.
     *
     * Used by FireDispatcher's overlap check: the prior slot's full record is
     * needed to compare dispatchedAt against the assumed-done TTL watermark.
     * findRunState() only returns the state enum; this returns the complete record
     * including dispatchedAt.
     *
     * Tenant scope: same rules as findLastSlots().
     */
    public function findRunBySlot(ScheduleId $scheduleId, string $slot, ?string $tenantId): ?ScheduleRun;

    /**
     * Delete completed/failed runs older than $before. Dispatched (in-flight) runs
     * are never pruned.
     *
     * Batched internally (bounded rows-per-statement and a wall-clock budget) — a
     * large first-run backlog on an upgraded install is pruned across several calls,
     * not one unbounded DELETE. PruneResult::$truncated tells the caller whether the
     * budget was exhausted with more rows potentially still eligible; this is a
     * normal, expected outcome, not an error.
     *
     * Tenant scope — exactly one of the following two shapes, never both:
     *   - $tenantId = 'abc', $excludeTenantIds = []      → prune only tenant 'abc'.
     *   - $tenantId = null,  $excludeTenantIds = [...]   → prune every tenant EXCEPT
     *     the listed ones (and tenant_id IS NULL rows). Empty $excludeTenantIds with
     *     $tenantId = null reproduces the original unscoped, all-tenants behavior.
     * Passing both a non-null $tenantId and a non-empty $excludeTenantIds is invalid.
     *
     * Used by RunRetentionSweeper (auto-prune) to prune each tenant at its own
     * resolved retention in one sweep, and by the manual scheduler:prune CLI.
     * Safe to call concurrently — the WHERE clause restricts to terminal states only.
     *
     * @param list<string> $excludeTenantIds
     *
     * @throws \InvalidArgumentException if both $tenantId and $excludeTenantIds are set
     */
    public function pruneOldRuns(DateTimeImmutable $before, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult;

    /**
     * Bulk-fetch the most recent dispatched_at per schedule. Single query for N schedules.
     *
     *   Returns map<scheduleId-string => DateTimeImmutable|null>
     *   Null means "this schedule has never dispatched a run".
     *
     * Used by DeadManDetector (S8) to identify overdue schedules in one round-trip.
     *
     * Tenant scope:
     *   tenantId = null  → no tenant filter (all tenants)
     *   tenantId = 'abc' → only runs for tenant 'abc'
     *
     * @param  list<ScheduleId> $scheduleIds
     * @return array<string, ?\DateTimeImmutable>
     */
    public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array;
}
