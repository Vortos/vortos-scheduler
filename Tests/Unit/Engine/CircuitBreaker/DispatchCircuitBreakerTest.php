<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine\CircuitBreaker;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Engine\CircuitBreaker\CircuitBreakerState;
use Vortos\Scheduler\Engine\CircuitBreaker\DispatchCircuitBreaker;
use Vortos\Scheduler\Engine\Exception\FireDispatchException;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Engine\FireDispatcherPort;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;

/**
 * Unit tests for the DispatchCircuitBreaker (E3).
 *
 * The circuit breaker wraps FireDispatcherPort and opens after N consecutive failures.
 * In the Open state it returns CircuitOpen without calling the inner dispatcher.
 * After the recovery window has elapsed it transitions to HalfOpen for one probe attempt.
 */
final class DispatchCircuitBreakerTest extends TestCase
{
    private MutableClock $clock;
    private Schedule $schedule;
    private ScheduledFire $fire;

    protected function setUp(): void
    {
        $this->clock    = new MutableClock(new DateTimeImmutable('2026-07-01T10:00:00Z', new DateTimeZone('UTC')));
        $this->schedule = $this->makeSchedule();
        $this->fire     = $this->makeFire($this->schedule->id);
    }

    public function test_initial_state_is_closed(): void
    {
        $cb = $this->makeCb(dispatcherResult: FireDispatchResult::Dispatched);

        self::assertSame(CircuitBreakerState::Closed, $cb->getState());
        self::assertSame(0, $cb->getConsecutiveFailures());
    }

    public function test_successful_dispatch_stays_closed_and_returns_result(): void
    {
        $cb = $this->makeCb(dispatcherResult: FireDispatchResult::Dispatched);

        $result = $cb->dispatch($this->fire, $this->schedule);

        self::assertSame(FireDispatchResult::Dispatched, $result);
        self::assertSame(CircuitBreakerState::Closed, $cb->getState());
        self::assertSame(0, $cb->getConsecutiveFailures());
    }

    public function test_single_failure_does_not_open(): void
    {
        $cb = $this->makeCb(dispatcherException: new FireDispatchException($this->fire, 'backend down'));

        try {
            $cb->dispatch($this->fire, $this->schedule);
        } catch (FireDispatchException) {}

        self::assertSame(CircuitBreakerState::Closed, $cb->getState());
        self::assertSame(1, $cb->getConsecutiveFailures());
    }

    public function test_n_consecutive_failures_opens_circuit(): void
    {
        $threshold = 3;
        $cb = $this->makeCbWithThreshold($threshold, dispatcherException: new FireDispatchException($this->fire, 'x'));

        for ($i = 0; $i < $threshold; $i++) {
            try {
                $cb->dispatch($this->fire, $this->schedule);
            } catch (FireDispatchException) {}
        }

        self::assertSame(CircuitBreakerState::Open, $cb->getState());
        self::assertSame($threshold, $cb->getConsecutiveFailures());
    }

    public function test_open_circuit_returns_circuit_open_without_calling_inner(): void
    {
        $threshold  = 3;
        $callCount  = 0;
        $failFire   = $this->fire;
        $inner      = $this->makeInnerWithCallback(
            function () use (&$callCount, $failFire): FireDispatchResult {
                $callCount++;
                throw new FireDispatchException($failFire, 'x');
            },
        );
        $cb = new DispatchCircuitBreaker($inner, $this->clock, $threshold, 30);

        // Trip the breaker
        for ($i = 0; $i < $threshold; $i++) {
            try { $cb->dispatch($this->fire, $this->schedule); } catch (\Throwable) {}
        }

        $callCount = 0; // Reset after tripping

        $result = $cb->dispatch($this->fire, $this->schedule);

        self::assertSame(FireDispatchResult::CircuitOpen, $result);
        self::assertSame(0, $callCount, 'Inner should not be called when circuit is Open');
    }

    public function test_recovery_window_transitions_to_half_open(): void
    {
        $calls    = 0;
        $failFire = $this->fire;
        $inner    = $this->makeInnerWithCallback(
            function () use (&$calls, $failFire): FireDispatchResult {
                $calls++;
                if ($calls <= 3) {
                    throw new FireDispatchException($failFire, 'x');
                }
                return FireDispatchResult::Dispatched;
            },
        );
        $cb = new DispatchCircuitBreaker($inner, $this->clock, 3, 30);

        // Trip the breaker
        for ($i = 0; $i < 3; $i++) {
            try { $cb->dispatch($this->fire, $this->schedule); } catch (\Throwable) {}
        }
        self::assertSame(CircuitBreakerState::Open, $cb->getState());

        // Advance past recovery window → next dispatch probes in HalfOpen, inner succeeds
        $this->clock->advanceSeconds(31);

        $result = $cb->dispatch($this->fire, $this->schedule);

        self::assertSame(FireDispatchResult::Dispatched, $result);
        self::assertSame(CircuitBreakerState::Closed, $cb->getState());
    }

    public function test_half_open_probe_failure_re_opens_circuit(): void
    {
        $threshold = 2;
        $fail      = new FireDispatchException($this->fire, 'x');
        $calls     = 0;
        $inner     = $this->makeInnerWithCallback(
            function () use ($fail, &$calls): FireDispatchResult {
                $calls++;
                throw $fail;
            },
        );
        $cb = new DispatchCircuitBreaker($inner, $this->clock, $threshold, 30);

        // Trip
        for ($i = 0; $i < $threshold; $i++) {
            try { $cb->dispatch($this->fire, $this->schedule); } catch (\Throwable) {}
        }
        // Advance past recovery
        $this->clock->advanceSeconds(31);

        // Probe — fails again
        try { $cb->dispatch($this->fire, $this->schedule); } catch (\Throwable) {}

        self::assertSame(CircuitBreakerState::Open, $cb->getState(), 'Should re-open after probe failure');
    }

    public function test_success_resets_consecutive_failure_count(): void
    {
        $failures = 0;
        $failFire = $this->fire;
        $inner    = $this->makeInnerWithCallback(
            function () use (&$failures, $failFire): FireDispatchResult {
                $failures++;
                if ($failures < 2) {
                    throw new FireDispatchException($failFire, 'x');
                }
                return FireDispatchResult::Dispatched;
            },
        );
        $cb = new DispatchCircuitBreaker($inner, $this->clock, 5, 30);

        try { $cb->dispatch($this->fire, $this->schedule); } catch (\Throwable) {}
        self::assertSame(1, $cb->getConsecutiveFailures());

        $cb->dispatch($this->fire, $this->schedule);
        self::assertSame(0, $cb->getConsecutiveFailures());
    }

    public function test_circuit_open_result_is_not_counted_as_failure(): void
    {
        $cb = $this->makeCbWithThreshold(3, dispatcherException: new FireDispatchException($this->fire, 'x'));

        for ($i = 0; $i < 3; $i++) {
            try { $cb->dispatch($this->fire, $this->schedule); } catch (\Throwable) {}
        }

        $failuresBefore = $cb->getConsecutiveFailures();

        // Multiple open-circuit calls
        $cb->dispatch($this->fire, $this->schedule);
        $cb->dispatch($this->fire, $this->schedule);

        self::assertSame($failuresBefore, $cb->getConsecutiveFailures(), 'CircuitOpen result must not increment failure count');
    }

    public function test_non_fire_dispatch_exception_still_counts_as_failure(): void
    {
        $inner = $this->makeInnerWithCallback(fn() => throw new \RuntimeException('infra error'));
        $cb    = new DispatchCircuitBreaker($inner, $this->clock, 2, 30);

        try { $cb->dispatch($this->fire, $this->schedule); } catch (\Throwable) {}
        try { $cb->dispatch($this->fire, $this->schedule); } catch (\Throwable) {}

        self::assertSame(CircuitBreakerState::Open, $cb->getState());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeCb(
        ?FireDispatchResult $dispatcherResult = null,
        ?\Throwable $dispatcherException = null,
        ?DispatchCircuitBreaker $existingCb = null,
    ): DispatchCircuitBreaker {
        return $this->makeCbWithThreshold(5, $dispatcherResult, $dispatcherException, $existingCb);
    }

    private function makeCbWithThreshold(
        int $threshold,
        ?FireDispatchResult $dispatcherResult = null,
        ?\Throwable $dispatcherException = null,
        ?DispatchCircuitBreaker $existingCb = null,
    ): DispatchCircuitBreaker {
        if ($existingCb !== null) {
            return $existingCb;
        }

        $inner = $this->makeInnerWithCallback(
            function () use ($dispatcherResult, $dispatcherException): FireDispatchResult {
                if ($dispatcherException !== null) {
                    throw $dispatcherException;
                }
                return $dispatcherResult ?? FireDispatchResult::Dispatched;
            },
        );

        return new DispatchCircuitBreaker($inner, $this->clock, $threshold, 30);
    }

    private function makeInnerWithCallback(callable $callback): FireDispatcherPort
    {
        return new class($callback) implements FireDispatcherPort {
            public function __construct(private $callback) {}
            public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
            {
                return ($this->callback)($fire, $schedule);
            }
        };
    }

    private function makeSchedule(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'cb-test',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('Vortos\Scheduler\Tests\Unit\Engine\FakeCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }

    private function makeFire(ScheduleId $id): ScheduledFire
    {
        return new ScheduledFire(
            scheduleId:   $id,
            tenantId:     null,
            slot:         $id->toString() . ':2026-07-01T10:00:00+00:00',
            scheduledFor: new DateTimeImmutable('2026-07-01T10:00:00Z', new DateTimeZone('UTC')),
            attempt:      1,
        );
    }
}
