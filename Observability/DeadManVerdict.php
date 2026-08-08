<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Observability;

/**
 * What the dead-man check concluded about one schedule.
 *
 * The check used to be binary: a dispatch inside the tolerance window, or an alert. That collapsed
 * two very different findings into one. "This schedule fired and then stopped" and "I have no
 * record of this schedule ever firing" have the same shape in the run ledger — zero usable rows —
 * and completely different meanings. A schedule registered ninety seconds ago has no runs and is
 * perfectly healthy. A schedule registered a month ago with no runs is an outage.
 *
 * Reported as one state, the first case pages on every deployment that introduces a schedule, which
 * is how an alarm gets ignored, and then the second case pages into a channel nobody is reading any
 * more. Both prod false positives that prompted this were the first case: each fired before its
 * schedule's first-ever dispatch.
 */
enum DeadManVerdict: string
{
    /** A dispatch landed inside the tolerance window, or the schedule was never due within it. */
    case Fired = 'fired';

    /** A dispatch exists, but it is older than the tolerance window. The schedule has stopped. */
    case Overdue = 'overdue';

    /**
     * No dispatch has ever been recorded, and the schedule has been known for longer than the
     * tolerance window — long enough that it should have fired by now.
     */
    case NeverFired = 'never_fired';

    /**
     * The check could not be made: the dispatch history was unreadable, or there is no record of
     * when the schedule was first seen, so "never fired" cannot be distinguished from "brand new".
     *
     * Deliberately not silent. An un-runnable check is itself a monitoring failure, and the entire
     * reason this component exists is that a monitor which quietly stops working looks exactly like
     * a system with nothing wrong.
     */
    case Indeterminate = 'indeterminate';

    /** Whether this verdict should reach a human. */
    public function isAlertable(): bool
    {
        return $this !== self::Fired;
    }
}
