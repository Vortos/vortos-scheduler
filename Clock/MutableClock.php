<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Clock;

use DateInterval;
use DateTimeImmutable;

/**
 * Test clock — freeze or advance time deterministically.
 * Never registered in the production container; constructed directly in test cases.
 */
final class MutableClock implements ClockPort
{
    public function __construct(private DateTimeImmutable $current) {}

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function freeze(DateTimeImmutable $at): void
    {
        $this->current = $at;
    }

    public function advance(DateInterval $by): void
    {
        $this->current = $this->current->add($by);
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->current = $this->current->modify("+{$seconds} seconds");
    }

    public function advanceMinutes(int $minutes): void
    {
        $this->current = $this->current->modify("+{$minutes} minutes");
    }
}
