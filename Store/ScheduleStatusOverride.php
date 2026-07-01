<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store;

use DateTimeImmutable;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleStatus;

final readonly class ScheduleStatusOverride
{
    public function __construct(
        public ScheduleId         $scheduleId,
        public ScheduleStatus     $status,
        public string             $actorId,
        public ?string            $reason,
        public DateTimeImmutable  $updatedAt,
    ) {}
}
