<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine\CircuitBreaker;

use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Engine\FireDispatcherPort;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * Circuit-breaker wrapper for {@see FireDispatcherPort}.
 *
 * When the underlying dispatcher throws consecutively `$failureThreshold` times
 * (default 5), the circuit opens. During the open window (`$recoveryWindowSec`,
 * default 30 s), all dispatch calls return `FireDispatchResult::CircuitOpen`
 * immediately — protecting the DB/outbox from sustained hammering while it is down.
 *
 * After `$recoveryWindowSec` the circuit enters HalfOpen: one probe attempt is
 * made. Success → circuit closes. Failure → window resets.
 *
 * This is an in-process, single-instance guard. It does NOT replace the
 * daemon-level exponential backoff; both work together. The circuit breaker stops
 * per-fire log storms (1000 error entries per tick with 1000 schedules); the
 * daemon backoff stops the tick loop itself.
 *
 * Correctness invariant: a CircuitOpen result does NOT insert a ledger row. The
 * next time the circuit closes, the same slot will be seen as due and dispatched
 * normally (or recognised as already-dispatched if the backend recovered and the
 * previous attempt partially committed — the UNIQUE constraint handles that).
 */
final class DispatchCircuitBreaker implements FireDispatcherPort
{
    private CircuitBreakerState $state             = CircuitBreakerState::Closed;
    private int                 $consecutiveFailures = 0;
    private ?\DateTimeImmutable $openedAt           = null;

    public function __construct(
        private readonly FireDispatcherPort $inner,
        private readonly ClockPort          $clock,
        private readonly int $failureThreshold  = 5,
        private readonly int $recoveryWindowSec = 30,
    ) {
        if ($failureThreshold < 1) {
            throw new \InvalidArgumentException("failureThreshold must be >= 1, got {$failureThreshold}.");
        }
        if ($recoveryWindowSec < 1) {
            throw new \InvalidArgumentException("recoveryWindowSec must be >= 1, got {$recoveryWindowSec}.");
        }
    }

    public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
    {
        if ($this->state === CircuitBreakerState::Open) {
            $elapsed = $this->clock->now()->getTimestamp() - ($this->openedAt?->getTimestamp() ?? 0);

            if ($elapsed < $this->recoveryWindowSec) {
                return FireDispatchResult::CircuitOpen;
            }

            $this->state = CircuitBreakerState::HalfOpen;
        }

        try {
            $result = $this->inner->dispatch($fire, $schedule);
            $this->onSuccess();
            return $result;
        } catch (\Throwable $e) {
            $this->onFailure();
            throw $e;
        }
    }

    public function getState(): CircuitBreakerState
    {
        return $this->state;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    private function onSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->state               = CircuitBreakerState::Closed;
        $this->openedAt            = null;
    }

    private function onFailure(): void
    {
        $this->consecutiveFailures++;

        if ($this->consecutiveFailures >= $this->failureThreshold) {
            $this->state    = CircuitBreakerState::Open;
            $this->openedAt = $this->clock->now();
        }
    }
}
