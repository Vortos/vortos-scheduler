<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store;

use DateTimeImmutable;
use Vortos\Scheduler\Schedule\ScheduleId;

/**
 * Persists each schedule's cadence cursor — the first-class, typed position from which the daemon
 * computes the next automatic fires. This is deliberately SEPARATE from the execution log
 * (scheduler_runs): manual `run-now` fires, backfills and replays write runs but never touch the
 * cursor, so out-of-band execution can never perturb automatic cadence.
 *
 * The table is keyed by schedule_id only, so it serves static (compile-time) and dynamic (DB)
 * schedules uniformly — static schedules have no scheduler_schedules row but still get a cursor.
 */
interface ScheduleCursorStoreInterface
{
    /**
     * Bulk-fetch cadence cursors for the given schedules. Single query for N schedules.
     *
     * Missing entries mean "this schedule has never been scanned"; the daemon anchors those to the
     * current instant (no retroactive catch-up) rather than to the catch-up horizon.
     *
     * Tenant scope:
     *   tenantId = null  → daemon mode; no tenant filter (cursors for all tenants visible)
     *   tenantId = 'abc' → only cursors for tenant 'abc' are considered
     *
     * @param  list<ScheduleId> $scheduleIds
     * @return array<string, CadenceCursor>  keyed by scheduleId string
     */
    public function findCursors(array $scheduleIds, ?string $tenantId): array;

    /**
     * Compare-and-swap the cadence cursor forward.
     *
     * $expectedVersion = 0 inserts a fresh cursor row (used the first time a schedule is scanned);
     * a concurrent insert by another node makes this return false. $expectedVersion > 0 updates the
     * existing row only if its version still matches, incrementing it on success.
     *
     * @return bool true if this node advanced the cursor; false on a lost race (another node moved
     *              it first) — the caller should log and move on, the next scan reconciles.
     */
    public function advance(
        ScheduleId        $id,
        ?string           $tenantId,
        DateTimeImmutable $newCursor,
        int               $expectedVersion,
    ): bool;
}
