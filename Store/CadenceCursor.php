<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store;

use DateTimeImmutable;
use Vortos\Scheduler\Schedule\ScheduleId;

/**
 * Persisted cadence position for a single schedule.
 *
 * $cursorAt is the instant up to which the schedule's cadence has been settled; the next scan
 * enumerates trigger slots in ($cursorAt, now]. $version is an optimistic-lock counter used by
 * {@see ScheduleCursorStoreInterface::advance()} — a fresh (never-persisted) cursor has version 0.
 */
final readonly class CadenceCursor
{
    public function __construct(
        public ScheduleId        $scheduleId,
        public ?string           $tenantId,
        public DateTimeImmutable $cursorAt,
        public int               $version,
    ) {}
}
