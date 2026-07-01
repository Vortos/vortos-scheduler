<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule\Trigger;

use DateTimeImmutable;

/**
 * Fires exactly once at a fixed instant.
 * Returns null when $after >= $fireAt — the slot has already been dispatched
 * (or the instant is in the past), so the schedule will never fire again.
 */
final readonly class OneShotTrigger implements Trigger
{
    public function __construct(public readonly DateTimeImmutable $fireAt) {}

    public function nextRunAfter(DateTimeImmutable $after): ?DateTimeImmutable
    {
        // Strict "strictly after": equality means the slot was already processed.
        return $after < $this->fireAt ? $this->fireAt : null;
    }

    public function describe(): string
    {
        return sprintf('@%s', $this->fireAt->format(DateTimeImmutable::ATOM));
    }
}
