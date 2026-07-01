<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Registry;

use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Store\Exception\ScheduleNameCollisionException;
use Vortos\Scheduler\Store\ScheduleStatusOverrideStoreInterface;
use Vortos\Scheduler\Store\ScheduleStoreInterface;

/**
 * Authoritative active-schedule view for the SchedulerDaemon.
 *
 * Merges static schedules (compile-time, from StaticScheduleRegistry) with
 * dynamic schedules (runtime DB, from ScheduleStoreInterface) into one iterable.
 *
 * Collision detection (static ↔ dynamic):
 *  - Name collision: a dynamic schedule with tenantId=null whose name matches a
 *    static schedule's name. Names are tenant-namespaced, so a dynamic schedule
 *    for tenant 'T1' with the same name as a static (system-scoped) schedule is
 *    NOT a collision.
 *  - ID collision: any dynamic schedule sharing an ID with any static schedule,
 *    regardless of tenant. IDs are globally unique.
 *
 * On collision: ScheduleNameCollisionException is thrown immediately. The daemon's
 * outer try/catch applies exponential backoff. Operator must resolve via rename or
 * deletion; scheduler:doctor (S9) surfaces this before deploy.
 *
 * Yield order: static schedules (Active only) → dynamic schedules (Active only).
 * Dynamic schedules filtered by the store's findAllActive() contract.
 * Static schedule status comes from build() — pausing statics at runtime requires
 * a DB override row (scheduler:pause command, S9).
 */
final class ScheduleResolver
{
    public function __construct(
        private readonly StaticScheduleRegistry                $registry,
        private readonly ScheduleStoreInterface                $store,
        private readonly ?ScheduleStatusOverrideStoreInterface $overrideStore = null,
    ) {}

    /**
     * The authoritative view of all active schedules for the daemon.
     *
     * This is a generator — iteration is lazy. The collision detection for dynamic
     * schedules runs as each dynamic schedule is yielded, not upfront.
     * All static schedules are yielded before any dynamic schedule collision can be
     * detected, so statics always reach the dispatcher even when a dynamic collision
     * exists.
     *
     * Runtime-paused statics (via overrideStore) are suppressed here.
     *
     * @return iterable<Schedule>
     * @throws ScheduleNameCollisionException when a static ↔ dynamic name or ID collision is detected
     */
    public function activeView(): iterable
    {
        $staticSchedules = $this->registry->all();

        // Build O(1) lookup tables for collision detection against dynamic schedules.
        // Only system-scoped (tenantId=null) static names are checked — name uniqueness
        // is tenant-namespaced; a tenant-scoped dynamic schedule may share a name with a
        // system-scoped static without conflict.
        $staticSystemNames = [];
        $staticIds         = [];

        foreach ($staticSchedules as $schedule) {
            $staticSystemNames[$schedule->name]   = true;
            $staticIds[$schedule->id->toString()] = true;
        }

        // Build paused-static lookup from override store.
        $pausedStaticIds = [];
        if ($this->overrideStore !== null) {
            foreach ($this->overrideStore->findAllPaused() as $override) {
                $pausedStaticIds[$override->scheduleId->toString()] = true;
            }
        }

        // 1. Yield Active static schedules first (respecting runtime pauses).
        foreach ($staticSchedules as $schedule) {
            if ($schedule->status === ScheduleStatus::Active
                && !isset($pausedStaticIds[$schedule->id->toString()])) {
                yield $schedule;
            }
        }

        // 2. Yield Active dynamic schedules with collision detection.
        foreach ($this->store->findAllActive() as $dynamic) {
            // Name collision: system-scoped dynamic vs static (both tenantId=null).
            if ($dynamic->tenantId === null && isset($staticSystemNames[$dynamic->name])) {
                throw new ScheduleNameCollisionException(\sprintf(
                    'Dynamic schedule "%s" (id: %s, tenantId: null) has the same system-scoped name '
                    . 'as a static schedule. Rename the dynamic schedule or remove the static '
                    . 'definition. Run scheduler:doctor to audit all collisions before next deploy.',
                    $dynamic->name,
                    $dynamic->id->toString(),
                ));
            }

            // ID collision: IDs must be globally unique across static and dynamic.
            if (isset($staticIds[$dynamic->id->toString()])) {
                throw new ScheduleNameCollisionException(\sprintf(
                    'Dynamic schedule id "%s" (name: "%s", tenantId: %s) collides with a static '
                    . 'schedule. Schedule IDs must be globally unique across all sources. '
                    . 'Run scheduler:doctor to audit before next deploy.',
                    $dynamic->id->toString(),
                    $dynamic->name,
                    $dynamic->tenantId ?? 'null',
                ));
            }

            yield $dynamic;
        }
    }

    /**
     * Full merged view for operator display (scheduler:list, Admin UI).
     *
     * Returns ALL schedules (any status) with runtime override status applied.
     * Unlike activeView(), this does NOT filter by Active status and does NOT
     * perform collision detection.
     *
     * For dynamic schedules: if tenantId is provided, returns only that tenant's
     * schedules. If null, returns system-scoped dynamics (tenantId = null in DB).
     *
     * @return iterable<Schedule>
     */
    public function fullView(?string $tenantId = null): iterable
    {
        // Build paused-static lookup from override store.
        $pausedStaticIds = [];
        if ($this->overrideStore !== null) {
            foreach ($this->overrideStore->findAllPaused() as $override) {
                $pausedStaticIds[$override->scheduleId->toString()] = true;
            }
        }

        // Yield statics with override applied.
        foreach ($this->registry->all() as $schedule) {
            if (isset($pausedStaticIds[$schedule->id->toString()])) {
                yield $schedule->withStatus(ScheduleStatus::Paused);
            } else {
                yield $schedule;
            }
        }

        // Yield dynamics (any status).
        foreach ($this->store->findAll($tenantId) as $schedule) {
            yield $schedule;
        }
    }

    /**
     * Total count of registered static schedules (any status).
     * Used by scheduler:doctor (S9) to verify static discovery is working.
     */
    public function staticCount(): int
    {
        return \count($this->registry->all());
    }

    /** True when at least one static schedule is registered. */
    public function hasStaticSchedules(): bool
    {
        return !$this->registry->isEmpty();
    }

    /**
     * Expose the registry for read-only access by CLI commands (S9).
     */
    public function getRegistry(): StaticScheduleRegistry
    {
        return $this->registry;
    }
}
