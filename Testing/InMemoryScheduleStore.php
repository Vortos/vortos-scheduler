<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use DateTimeImmutable;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Store\Exception\OptimisticLockException;
use Vortos\Scheduler\Store\Exception\ScheduleNameConflictException;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\Scheduler\Store\ScheduleStoreInterface;

/**
 * Pure in-memory ScheduleStoreInterface for unit tests.
 * Does not enforce CAS — tests that need CAS should use the DBAL driver.
 */
final class InMemoryScheduleStore implements ScheduleStoreInterface
{
    /** @var array<string, Schedule> keyed by id */
    private array $schedules = [];

    public function seed(Schedule $schedule): void
    {
        $this->schedules[$schedule->id->toString()] = $schedule;
    }

    public function save(Schedule $schedule): void
    {
        $this->schedules[$schedule->id->toString()] = $schedule;
    }

    public function find(ScheduleId $id, ?string $tenantId): ?Schedule
    {
        $s = $this->schedules[$id->toString()] ?? null;
        if ($s === null) {
            return null;
        }
        if ($s->tenantId !== $tenantId) {
            return null;
        }
        return $s;
    }

    public function findByName(string $name, ?string $tenantId): ?Schedule
    {
        foreach ($this->schedules as $s) {
            if ($s->name === $name && $s->tenantId === $tenantId) {
                return $s;
            }
        }
        return null;
    }

    public function delete(ScheduleId $id, ?string $tenantId): void
    {
        if (!isset($this->schedules[$id->toString()])) {
            throw new ScheduleNotFoundException($id->toString(), $tenantId);
        }
        unset($this->schedules[$id->toString()]);
    }

    public function findActive(?string $tenantId): iterable
    {
        foreach ($this->schedules as $s) {
            if ($s->tenantId === $tenantId && $s->status === ScheduleStatus::Active) {
                yield $s;
            }
        }
    }

    public function findAllActive(): iterable
    {
        foreach ($this->schedules as $s) {
            if ($s->status === ScheduleStatus::Active) {
                yield $s;
            }
        }
    }

    public function findAll(?string $tenantId): iterable
    {
        foreach ($this->schedules as $s) {
            if ($s->tenantId === $tenantId) {
                yield $s;
            }
        }
    }

    public function reset(): void
    {
        $this->schedules = [];
    }
}
