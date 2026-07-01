<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store;

use Vortos\Scheduler\Schedule\ScheduleId;

interface ScheduleStatusOverrideStoreInterface
{
    /**
     * Upsert an override row. Replaces any prior override for the same scheduleId.
     * Called by ScheduleService::pause() and ::resume() for static schedules.
     */
    public function save(ScheduleStatusOverride $override): void;

    /**
     * Fetch the current override for a static schedule, or null if none exists
     * (meaning the static schedule's compiled status is authoritative).
     */
    public function find(ScheduleId $id): ?ScheduleStatusOverride;

    /**
     * Remove the override row (restores the compiled-in status for the static schedule).
     * Called by ScheduleService::resume() for static schedules. Idempotent.
     */
    public function remove(ScheduleId $id): void;

    /**
     * Return all override rows with status = Paused.
     * Used by ScheduleService::listSchedules() and scheduler:doctor to merge
     * pause state back into static schedules for display and validation.
     *
     * @return list<ScheduleStatusOverride>
     */
    public function findAllPaused(): array;
}
