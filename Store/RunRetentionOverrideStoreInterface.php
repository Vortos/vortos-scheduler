<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store;

interface RunRetentionOverrideStoreInterface
{
    /**
     * Upsert an override row. Replaces any prior override for the same tenantId.
     * Called by ScheduleService::setRunRetentionOverride().
     */
    public function save(RunRetentionOverride $override): void;

    /**
     * Fetch the current override for a tenant, or null if none exists (meaning
     * the global runRetentionDays default is authoritative for this tenant).
     */
    public function find(string $tenantId): ?RunRetentionOverride;

    /**
     * Remove the override row (restores the global default for this tenant).
     * Called by ScheduleService::removeRunRetentionOverride(). Idempotent.
     */
    public function remove(string $tenantId): void;

    /**
     * Return every override row. Used by RunRetentionSweeper to resolve each
     * overridden tenant's cutoff, and by SchedulerDoctor (C10) to report them.
     *
     * @return list<RunRetentionOverride>
     */
    public function findAll(): array;
}
