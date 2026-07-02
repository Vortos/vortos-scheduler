<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use DateTimeImmutable;
use Vortos\Scheduler\Fire\ScheduledFire;

/**
 * Output of MisfireResolver::resolve().
 *
 * $fires     — slots the policy selected to dispatch, sorted scheduledFor ASC.
 * $dropped   — slots dropped for being beyond the max_catchup_age horizon (audit).
 * $newCursor — the instant up to which cadence has been *settled* this evaluation.
 *              The daemon persists this as the schedule's new cadence cursor once the
 *              corresponding fires are dispatched. It advances even when $fires is empty
 *              (e.g. SkipMissed consciously abandoning missed slots), which is what
 *              prevents the never-advancing-anchor deadlock. For FireEachMissed with a
 *              truncated catch-up batch it is the last *fired* slot, so the remaining
 *              backlog is drained on subsequent ticks rather than abandoned.
 */
final readonly class MisfireResolution
{
    /**
     * @param list<ScheduledFire>     $fires
     * @param list<DroppedSlotRecord> $dropped
     */
    public function __construct(
        public array             $fires,
        public array             $dropped,
        public DateTimeImmutable $newCursor,
    ) {}
}
