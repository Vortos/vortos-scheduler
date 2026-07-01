<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use DateTimeImmutable;
use Vortos\Scheduler\Schedule\ScheduleId;

/**
 * A slot that was dropped by MisfireResolver because it fell outside the
 * max_catchup_age horizon. Surfaced by DueScanResult so the daemon (S5)
 * can audit/log these drops without a second scan.
 */
final readonly class DroppedSlotRecord
{
    public const REASON_BEYOND_HORIZON = 'beyond_horizon';

    public function __construct(
        public ScheduleId        $scheduleId,
        public ?string           $tenantId,
        public DateTimeImmutable $droppedAt,  // the trigger instant that was dropped
        public string            $reason,     // e.g. REASON_BEYOND_HORIZON
    ) {}
}
