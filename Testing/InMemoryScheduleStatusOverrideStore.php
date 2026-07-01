<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Store\ScheduleStatusOverride;
use Vortos\Scheduler\Store\ScheduleStatusOverrideStoreInterface;

/**
 * Pure in-memory implementation of ScheduleStatusOverrideStoreInterface.
 * For use in unit tests that need the override store without a DB connection.
 */
final class InMemoryScheduleStatusOverrideStore implements ScheduleStatusOverrideStoreInterface
{
    /** @var array<string, ScheduleStatusOverride> */
    private array $overrides = [];

    public function save(ScheduleStatusOverride $override): void
    {
        $this->overrides[$override->scheduleId->toString()] = $override;
    }

    public function find(ScheduleId $id): ?ScheduleStatusOverride
    {
        return $this->overrides[$id->toString()] ?? null;
    }

    public function remove(ScheduleId $id): void
    {
        unset($this->overrides[$id->toString()]);
    }

    public function findAllPaused(): array
    {
        return array_values(array_filter(
            $this->overrides,
            static fn(ScheduleStatusOverride $o) => $o->status === ScheduleStatus::Paused,
        ));
    }

    /** Clear all overrides (test tearDown helper). */
    public function reset(): void
    {
        $this->overrides = [];
    }
}
