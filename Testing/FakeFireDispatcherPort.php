<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use Vortos\Scheduler\Engine\FireDispatcherPort;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * Spy implementation of FireDispatcherPort for unit tests.
 * Returns the configured result; records all dispatch calls.
 */
final class FakeFireDispatcherPort implements FireDispatcherPort
{
    /** @var list<array{fire: ScheduledFire, schedule: Schedule}> */
    public array $calls = [];

    public function __construct(
        private FireDispatchResult $result = FireDispatchResult::Dispatched,
    ) {}

    public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
    {
        $this->calls[] = ['fire' => $fire, 'schedule' => $schedule];
        return $this->result;
    }

    public function setResult(FireDispatchResult $result): void
    {
        $this->result = $result;
    }

    public function wasDispatched(): bool
    {
        return $this->calls !== [];
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    public function lastFire(): ?ScheduledFire
    {
        return empty($this->calls) ? null : $this->calls[array_key_last($this->calls)]['fire'];
    }
}
