<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Clock;

use DateTimeImmutable;

final class SystemClock implements ClockPort
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
