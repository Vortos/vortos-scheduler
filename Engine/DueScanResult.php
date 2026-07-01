<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use Vortos\Scheduler\Fire\ScheduledFire;

/**
 * Output of DueScan::compute().
 *
 * $fires   — slots to dispatch, sorted scheduledFor ASC (oldest first for catch-up ordering).
 * $dropped — slots beyond max_catchup_age horizon, not fired; surfaced for audit (S8).
 */
final readonly class DueScanResult
{
    /**
     * @param list<ScheduledFire>       $fires
     * @param list<DroppedSlotRecord>   $dropped
     */
    public function __construct(
        public array $fires,
        public array $dropped,
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
