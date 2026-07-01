<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use DateTimeImmutable;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Policy\FireEachMissed;
use Vortos\Scheduler\Schedule\Policy\FireOnceNow;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\SkipMissed;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * Pure engine — no I/O, no DBAL, no clock calls.
 *
 * Given a schedule, its last fired slot key, and the current time,
 * returns the set of ScheduledFires to dispatch plus any slots that
 * were dropped for being beyond the max_catchup_age horizon.
 *
 * MisfireResolver MUST remain dependency-free of any infrastructure
 * (enforced by SchedulerPurityArchTest).
 */
final class MisfireResolver
{
    public function __construct(
        private readonly SlotCalculator $slotCalculator,
    ) {}

    /**
     * @return array{fires: list<ScheduledFire>, dropped: list<DroppedSlotRecord>}
     */
    public function resolve(
        Schedule          $schedule,
        ?string           $lastSlotKey,
        DateTimeImmutable $now,
        int               $maxCatchupAgeSec = 86400,
    ): array {
        $horizon      = $now->modify("-{$maxCatchupAgeSec} seconds");
        $lastFireTime = $this->parseLastFireTime($lastSlotKey);

        // Anything before the horizon is unreachable — jump cursor to horizon.
        // Slots between lastFireTime and horizon are noted as a batch drop.
        $dropped = [];
        if ($lastFireTime !== null && $lastFireTime < $horizon) {
            $dropped[] = new DroppedSlotRecord(
                scheduleId: $schedule->id,
                tenantId:   $schedule->tenantId,
                droppedAt:  $horizon,
                reason:     DroppedSlotRecord::REASON_BEYOND_HORIZON,
            );
            $cursor = $horizon;
        } else {
            $cursor = $lastFireTime ?? $horizon;
        }

        // Enumerate all trigger slots in the half-open interval ($cursor, $now].
        $fires       = [];
        $maxIterations = 50_000;
        $iterations  = 0;

        while ($iterations++ < $maxIterations) {
            $next = $schedule->trigger->nextRunAfter($cursor);

            if ($next === null || $next > $now) {
                break;
            }

            $slot = $this->slotCalculator->slotKey($schedule->id, $next, $schedule->timezone);

            $fires[] = new ScheduledFire(
                scheduleId:   $schedule->id,
                tenantId:     $schedule->tenantId,
                slot:         $slot,
                scheduledFor: $next,
            );

            $cursor = $next;
        }

        $fires = $this->applyPolicy($schedule->misfire, $fires);

        return ['fires' => $fires, 'dropped' => $dropped];
    }

    /**
     * @param  list<ScheduledFire> $candidates   All slots in (lastFire, now] sorted ASC
     * @return list<ScheduledFire>
     */
    private function applyPolicy(MisfirePolicy $policy, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        return match (true) {
            $policy instanceof SkipMissed =>
                // Normal tick (exactly 1 slot) fires; misfire (>1 slot) skips all.
                count($candidates) === 1 ? $candidates : [],

            $policy instanceof FireOnceNow =>
                // Fire only the most recent missed slot (last in ASC-sorted list).
                [end($candidates)],

            $policy instanceof FireEachMissed =>
                // Fire oldest-first up to cap.
                array_slice($candidates, 0, $policy->cap),

            default =>
                throw new \LogicException(
                    'Unhandled MisfirePolicy subtype: ' . get_class($policy)
                ),
        };
    }

    /**
     * Extracts the scheduledFor instant from a slot key.
     *
     * Slot key format: "<36-char-uuid>:<ISO-8601-with-offset>"
     * UUID is always 36 characters (8-4-4-4-12 with dashes), so the colon
     * is at index 36 and the datetime string starts at index 37.
     *
     * Uses DateTimeImmutable constructor (not createFromFormat) because 'c'
     * is not a reliable format specifier for createFromFormat across PHP versions.
     * The constructor accepts any ISO 8601 string including '+00:00' offsets.
     */
    private function parseLastFireTime(?string $slotKey): ?DateTimeImmutable
    {
        if ($slotKey === null) {
            return null;
        }

        // UUID is always 36 characters (8-4-4-4-12 with dashes).
        if (strlen($slotKey) <= 37) {
            return null;
        }

        $dtString = substr($slotKey, 37);

        try {
            return new DateTimeImmutable($dtString);
        } catch (\Throwable) {
            return null;
        }
    }
}
