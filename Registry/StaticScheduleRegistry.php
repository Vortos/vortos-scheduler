<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Registry;

use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleSource;

/**
 * Read-only compile-time registry of static schedules.
 *
 * Holds the FQCNs of all StaticScheduleDefinition classes discovered and
 * validated by StaticSchedulePass at container-build time. On first all()
 * call, calls each class's static build() and caches the result forever.
 *
 * PHP is single-threaded — the lazy cache is safe without synchronization.
 */
final class StaticScheduleRegistry
{
    /** @var list<Schedule>|null null = not yet built */
    private ?array $schedules = null;

    /**
     * @param list<class-string<StaticScheduleDefinition>> $definitionClasses
     *   Injected by StaticSchedulePass with FQCNs that have been pre-validated
     *   (#[Scheduled] present, tenantId=null, source=Static, unique names+IDs).
     */
    public function __construct(
        private readonly array $definitionClasses = [],
    ) {}

    /**
     * All static schedules. Built once on first call; cached forever.
     * Order matches the compiler-pass discovery order (deterministic within a build).
     *
     * @return list<Schedule>
     */
    public function all(): array
    {
        if ($this->schedules === null) {
            $this->schedules = [];

            foreach ($this->definitionClasses as $class) {
                $schedule = $class::build();
                $this->guardSchedule($schedule, $class);
                $this->schedules[] = $schedule;
            }
        }

        return $this->schedules;
    }

    /** True when no static schedules are registered. */
    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * Find a static schedule by its string ID. Returns null if not found.
     * O(n) over static schedule count — acceptable given small, stable sets.
     */
    public function findById(string $id): ?Schedule
    {
        foreach ($this->all() as $schedule) {
            if ($schedule->id->toString() === $id) {
                return $schedule;
            }
        }

        return null;
    }

    /**
     * Find a static schedule by its name. Returns null if not found.
     */
    public function findByName(string $name): ?Schedule
    {
        foreach ($this->all() as $schedule) {
            if ($schedule->name === $name) {
                return $schedule;
            }
        }

        return null;
    }

    /**
     * Defence-in-depth: re-validate after build() in case build() changed
     * after the container was compiled without clearing the cache.
     *
     * @param class-string $class
     */
    private function guardSchedule(Schedule $schedule, string $class): void
    {
        if ($schedule->tenantId !== null) {
            throw new \LogicException(\sprintf(
                'Static schedule "%s" (class %s) returned tenantId = "%s". '
                . 'StaticScheduleDefinition::build() must return tenantId = null '
                . '(static schedules are always system-scoped). Clear the container cache.',
                $schedule->name,
                $class,
                $schedule->tenantId,
            ));
        }

        if ($schedule->source !== ScheduleSource::Static) {
            throw new \LogicException(\sprintf(
                'Static schedule "%s" (class %s) returned source = %s. '
                . 'StaticScheduleDefinition::build() must return source = ScheduleSource::Static. '
                . 'Clear the container cache.',
                $schedule->name,
                $class,
                $schedule->source->value,
            ));
        }
    }
}
