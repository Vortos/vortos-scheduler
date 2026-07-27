<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule\Trigger;

/**
 * Marks a trigger whose next fire instant is a FUNCTION OF THE ANCHOR it is given,
 * rather than of an absolute calendar grid.
 *
 * Why this distinction exists
 * ---------------------------
 * The daemon persists one cadence cursor per schedule and feeds it to
 * {@see Trigger::nextRunAfter()} as the anchor. For an absolute trigger
 * ({@see RecurringTrigger}, {@see OneShotTrigger}) the anchor only selects which
 * grid point comes next, so moving the anchor forward within a gap is harmless —
 * the answer is unchanged.
 *
 * For an anchor-relative trigger ({@see IntervalTrigger}) the anchor IS the origin:
 * next = anchor + interval. Moving the anchor forward moves every future fire with
 * it. If the daemon advances the cursor to `now` on a tick where nothing was due,
 * the origin is reset on every tick and the schedule can never reach its own
 * interval — permanently, silently. Any schedule whose interval exceeds the daemon's
 * tick period simply never fires.
 *
 * That is not hypothetical: it took out 10 of 14 production schedules (every
 * `@every` cadence above 60s — retry sweeps, payment reminders, FX refresh,
 * invitation expiry, audit retention) while `scheduler:list` reported all of them
 * "active" and their cursors visibly advanced each minute.
 *
 * The contract this interface buys
 * --------------------------------
 * {@see \Vortos\Scheduler\Engine\MisfireResolver} must not advance the cadence
 * cursor of an anchor-relative trigger across a window in which NOTHING was
 * settled. When at least one slot was enumerated the window is genuinely settled
 * (fired, or deliberately collapsed by a misfire policy) and the cursor advances
 * normally — which is what keeps {@see \Vortos\Scheduler\Schedule\Policy\SkipMissed}
 * from deadlocking.
 *
 * Implement this on any trigger where `nextRunAfter($a)` depends on the value of
 * `$a` beyond grid selection. Getting it wrong fails closed in
 * {@see \Vortos\Scheduler\Tests\Unit\Engine\MisfireResolverTest}.
 */
interface AnchorRelativeTrigger extends Trigger
{
}
