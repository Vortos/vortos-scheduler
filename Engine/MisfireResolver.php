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
use Vortos\Scheduler\Schedule\Trigger\AnchorRelativeTrigger;

/**
 * Pure engine — no I/O, no DBAL, no clock calls.
 *
 * Given a schedule, its persisted cadence cursor, and the current time, returns the set of
 * ScheduledFires to dispatch, any slots dropped for exceeding the max_catchup_age horizon, and
 * the advanced cursor to persist once those fires are dispatched.
 *
 * The cursor is first-class, typed state supplied by the caller (never reconstructed by parsing a
 * slot key out of the execution log) and is always non-null: a never-yet-scanned schedule is
 * anchored to its activation instant by the daemon, NOT to the catch-up horizon. The horizon is a
 * cap on genuine catch-up gaps only.
 *
 * MisfireResolver MUST remain dependency-free of any infrastructure
 * (enforced by SchedulerPurityArchTest).
 */
final class MisfireResolver
{
    public function __construct(
        private readonly SlotCalculator $slotCalculator,
    ) {}

    public function resolve(
        Schedule          $schedule,
        DateTimeImmutable $cursor,
        DateTimeImmutable $now,
        int               $maxCatchupAgeSec = 86400,
    ): MisfireResolution {
        $horizon = $now->modify("-{$maxCatchupAgeSec} seconds");

        // Anything before the horizon is unreachable — jump the working cursor to horizon and
        // note the batch drop. This is the horizon's ONLY role: capping a genuine catch-up gap.
        $dropped = [];
        if ($cursor < $horizon) {
            $dropped[] = new DroppedSlotRecord(
                scheduleId: $schedule->id,
                tenantId:   $schedule->tenantId,
                droppedAt:  $horizon,
                reason:     DroppedSlotRecord::REASON_BEYOND_HORIZON,
            );
            $workCursor = $horizon;
        } else {
            $workCursor = $cursor;
        }

        // Enumerate all trigger slots in the half-open interval ($workCursor, $now].
        $candidates    = [];
        $maxIterations = 50_000;
        $iterations    = 0;
        $enumCursor    = $workCursor;

        while ($iterations++ < $maxIterations) {
            $next = $schedule->trigger->nextRunAfter($enumCursor);

            if ($next === null || $next > $now) {
                break;
            }

            $slot = $this->slotCalculator->slotKey($schedule->id, $next, $schedule->timezone);

            $candidates[] = new ScheduledFire(
                scheduleId:   $schedule->id,
                tenantId:     $schedule->tenantId,
                slot:         $slot,
                scheduledFor: $next,
            );

            $enumCursor = $next;
        }

        $fires     = $this->applyPolicy($schedule->misfire, $candidates);
        $newCursor = $this->computeNewCursor($schedule, $candidates, $fires, $now, $workCursor);

        return new MisfireResolution(fires: $fires, dropped: $dropped, newCursor: $newCursor);
    }

    /**
     * @param  list<ScheduledFire> $candidates   All slots in ($cursor, now] sorted ASC
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
     * The cursor advances to the instant up to which cadence is settled.
     *
     * For every policy except a truncated FireEachMissed batch, the whole ($cursor, now] window
     * has been settled (fired, or deliberately skipped/collapsed), so the cursor advances to now.
     * A FireEachMissed batch that hit its cap has UNfired candidates remaining, so the cursor stops
     * at the last fired slot — the remainder drains on the next tick instead of being abandoned.
     *
     * EMPTY WINDOW + ANCHOR-RELATIVE TRIGGER: nothing was enumerated, so nothing was settled. For
     * an absolute trigger (cron, one-shot) sliding the cursor to `now` is a no-op on the answer, so
     * we still do it — that keeps enumeration cheap and keeps sparse schedules away from the
     * catch-up horizon. For an {@see AnchorRelativeTrigger} the cursor IS the origin of the next
     * fire, so sliding it forward re-bases the interval on every tick and the schedule can never
     * reach its own cadence. Such a schedule holds its anchor until a slot actually lands.
     *
     * This does NOT reintroduce the SkipMissed deadlock (Bug C): a skipped batch has candidates,
     * so the window was settled by deliberate collapse and the cursor still advances to now. Only
     * the genuinely-empty window holds.
     *
     * Never returns a value below $workCursor (guards clock skew / future cursors).
     *
     * @param list<ScheduledFire> $candidates
     * @param list<ScheduledFire> $fires
     */
    private function computeNewCursor(
        Schedule          $schedule,
        array             $candidates,
        array             $fires,
        DateTimeImmutable $now,
        DateTimeImmutable $workCursor,
    ): DateTimeImmutable {
        $policy = $schedule->misfire;

        if ($candidates === [] && $schedule->trigger instanceof AnchorRelativeTrigger) {
            return $workCursor;
        }

        if ($policy instanceof FireEachMissed && count($fires) < count($candidates) && $fires !== []) {
            $base = end($fires)->scheduledFor;
        } else {
            $base = $now;
        }

        return $base >= $workCursor ? $base : $workCursor;
    }
}
