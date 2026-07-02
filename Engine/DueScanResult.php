<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use DateTimeImmutable;
use Vortos\Scheduler\Fire\ScheduledFire;

/**
 * Output of DueScan::compute().
 *
 * $fires      — slots to dispatch, sorted scheduledFor ASC (oldest first for catch-up ordering).
 * $dropped    — slots beyond max_catchup_age horizon, not fired; surfaced for audit (S8).
 * $newCursors — map<scheduleId-string => advanced cadence cursor> to persist once the
 *               corresponding fires are dispatched. Contains an entry for every schedule that was
 *               evaluated, including those that produced no fires (so the cursor still advances and
 *               the anchor never stalls).
 */
final readonly class DueScanResult
{
    /**
     * @param list<ScheduledFire>              $fires
     * @param list<DroppedSlotRecord>          $dropped
     * @param array<string, DateTimeImmutable> $newCursors
     */
    public function __construct(
        public array $fires,
        public array $dropped,
        public array $newCursors = [],
    ) {}

    public function hasFires(): bool
    {
        return $this->fires !== [];
    }

    public function hasDropped(): bool
    {
        return $this->dropped !== [];
    }
}
