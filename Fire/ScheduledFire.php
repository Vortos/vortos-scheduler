<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Fire;

use DateTimeImmutable;
use Vortos\Scheduler\Schedule\ScheduleId;

/**
 * Immutable record of one intended fire — the output of DueScan and the
 * input of FireDispatcher.
 *
 * $slot is the human-readable slot key produced by SlotCalculator::slotKey().
 * It is computed by MisfireResolver so FireDispatcher does not recompute it.
 *
 * $scheduledFor is the trigger-defined instant (before jitter is applied).
 * Jitter offset is applied by FireDispatcher at dispatch time, not here.
 */
final readonly class ScheduledFire
{
    public function __construct(
        public ScheduleId        $scheduleId,
        public ?string           $tenantId,
        public string            $slot,           // SlotCalculator::slotKey() output
        public DateTimeImmutable $scheduledFor,   // trigger-defined instant, no jitter
        public int               $attempt = 1,
    ) {}
}
