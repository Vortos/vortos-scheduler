<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use Vortos\Scheduler\Engine\SchedulerEnqueuerPort;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Fire\ScheduledFire;

/**
 * Test stub that always throws on enqueue — used to drive circuit-breaker tests
 * and chaos scenarios where the backend is unavailable.
 */
final class FailingSchedulerEnqueuer implements SchedulerEnqueuerPort
{
    private int $callCount = 0;

    public function __construct(private readonly \Throwable $exception = new \RuntimeException('Enqueuer failure (test stub)')) {}

    public function enqueue(ScheduledFire $fire, Schedule $schedule): void
    {
        $this->callCount++;

        throw $this->exception;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}
