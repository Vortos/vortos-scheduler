<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule\Trigger;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Fires every $intervalSeconds seconds relative to the last fire.
 *
 * "Next fire" = $after + interval. No grid alignment by design — the daemon's
 * last-fired timestamp is the anchor. The fire-ledger slot prevents double-fire
 * even if two daemons race.
 *
 * Because the anchor is the origin rather than a grid selector, this trigger is an
 * {@see AnchorRelativeTrigger}: the engine must not slide its cadence cursor forward
 * across a window in which nothing was due, or the interval restarts every tick and
 * the schedule never fires. See that interface for the full rationale.
 *
 * Minimum interval: 1 second. Sub-second intervals are meaningless without
 * sub-second cron support and would cause a busy-poll loop in the daemon.
 * This is validated at construction (fail-fast), not just at scheduler:doctor.
 */
final readonly class IntervalTrigger implements AnchorRelativeTrigger
{
    public const MIN_INTERVAL_SECONDS = 1;

    private DateInterval $interval;

    public function __construct(public readonly int $intervalSeconds)
    {
        if ($intervalSeconds < self::MIN_INTERVAL_SECONDS) {
            throw new InvalidArgumentException(
                sprintf(
                    'IntervalTrigger minimum interval is %ds, got %ds.',
                    self::MIN_INTERVAL_SECONDS,
                    $intervalSeconds,
                )
            );
        }

        $this->interval = new DateInterval(sprintf('PT%dS', $intervalSeconds));
    }

    /**
     * Always returns a valid next instant — interval schedules never expire.
     * The narrowed return type (non-nullable) documents this at the type level.
     */
    public function nextRunAfter(DateTimeImmutable $after): DateTimeImmutable
    {
        return $after->add($this->interval);
    }

    public function describe(): string
    {
        return sprintf('@every %ds', $this->intervalSeconds);
    }
}
