<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store;

use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Store\Exception\OptimisticLockException;
use Vortos\Scheduler\Store\Exception\ScheduleNameConflictException;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;

/**
 * PORT: dynamic schedule CRUD + active-view feed for the daemon.
 *
 * Tenant isolation is enforced at this layer. Every method that reads or writes
 * a single tenant's data accepts a ?string $tenantId parameter:
 *   - null  = system-wide schedules (no tenantId)
 *   - 'abc' = schedules belonging to tenant 'abc'
 *
 * Cross-tenant reads/writes are impossible by construction — the driver
 * always includes the tenantId in its WHERE clause.
 *
 * The only exception is findAllActive(), which is reserved for the
 * SchedulerDaemon / ScheduleResolver (S5/S6) and must NOT be called
 * in tenant-request context (enforced by SchedulerStoreArchTest).
 */
interface ScheduleStoreInterface
{
    /**
     * Persist a schedule.
     *
     * INSERT when $schedule->version === 0 (new object from application code).
     * UPDATE when $schedule->version > 0 (fetched from store, CAS on version column).
     *
     * On INSERT: DB sets version = 1. The caller's in-memory object version remains 0;
     * re-fetch if you need the persisted version.
     * On UPDATE: DB does `version = version + 1` WHERE version = $schedule->version.
     *
     * @throws ScheduleNameConflictException  if (tenantId, name) already exists for a different id
     * @throws OptimisticLockException        if the stored version no longer matches (concurrent write)
     * @throws ScheduleNotFoundException      if version > 0 but the row no longer exists
     */
    public function save(Schedule $schedule): void;

    /**
     * Fetch a schedule by ID within a tenant scope.
     *
     * Returns null if no matching schedule exists for the given (id, tenantId) pair.
     * A schedule belonging to tenant B is NOT returned when tenantId is 'A'.
     * A system schedule (stored with null tenantId) is NOT returned when tenantId is non-null.
     */
    public function find(ScheduleId $id, ?string $tenantId): ?Schedule;

    /**
     * Fetch by name within a tenant scope.
     *
     * Same isolation rules as find(). Returns null if not found.
     */
    public function findByName(string $name, ?string $tenantId): ?Schedule;

    /**
     * Hard-delete a schedule. The run-ledger rows are NOT deleted — they are the permanent
     * idempotency and audit record. The audit log (S8) captures the deletion event.
     *
     * @throws ScheduleNotFoundException if no matching schedule exists for the given (id, tenantId)
     */
    public function delete(ScheduleId $id, ?string $tenantId): void;

    /**
     * Active schedules for one tenant scope (status = Active only).
     *
     *   tenantId = null  → system schedules only
     *   tenantId = 'abc' → tenant 'abc' schedules only
     *
     * Used by ScheduleResolver when building a per-tenant or per-system view.
     *
     * @return iterable<Schedule>
     */
    public function findActive(?string $tenantId): iterable;

    /**
     * Active schedules across ALL tenants and system (no tenant filter).
     *
     * RESERVED for SchedulerDaemon / ScheduleResolver (S5/S6).
     * MUST NOT be called in tenant-request context (enforced by architecture tests).
     *
     * @return iterable<Schedule>
     */
    public function findAllActive(): iterable;

    /**
     * All schedules in a tenant scope (any status). Used by scheduler:list CLI (S9).
     *
     * @return iterable<Schedule>
     */
    public function findAll(?string $tenantId): iterable;
}
