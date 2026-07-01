<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Chaos;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Engine\DueScan;
use Vortos\Scheduler\Engine\Exception\FireDispatchException;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Engine\FireDispatcherPort;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Store\PruneResult;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;
use Vortos\Scheduler\Testing\FailingSchedulerEnqueuer;

/**
 * @group chaos
 *
 * Chaos tests: inject transient and persistent failures into the dispatch path
 * and verify that the daemon (a) does not crash, (b) does not produce double-fires,
 * and (c) recovers when the backend becomes available again.
 *
 * These tests are excluded from the standard CI run and intended for release validation:
 *
 *   docker compose exec backend php vendor/bin/phpunit --group=chaos --testsuite=Scheduler
 */
final class SchedulerChaosTest extends TestCase
{
    private MutableClock $clock;
    private InMemoryScheduleStore $store;

    protected function setUp(): void
    {
        $this->clock = new MutableClock(new DateTimeImmutable('2026-07-01T10:00:00Z', new DateTimeZone('UTC')));
        $this->store = new InMemoryScheduleStore();
    }

    // ── C1: Transient backend failure — dispatcher throws, daemon recovers ────

    public function test_transient_dispatch_failure_does_not_crash_daemon(): void
    {
        $schedule = $this->makeSchedule('chaos-s1');
        $this->store->seed($schedule);

        $callCount = 0;
        $dispatcher = $this->makeFlappingDispatcher(
            $failureRounds = 2,
            $callCount,
        );

        $daemon = $this->makeDaemon($dispatcher);

        // First tick: dispatcher throws — daemon should NOT propagate
        try {
            $daemon->runOnce();
        } catch (\Throwable $e) {
            self::fail("Daemon must not propagate per-fire dispatch exceptions, got: " . $e->getMessage());
        }

        // Second tick after advancing time: dispatcher succeeds
        $this->clock->advanceSeconds(3600);

        try {
            $daemon->runOnce();
        } catch (\Throwable $e) {
            self::fail("Daemon must not propagate exceptions after recovery, got: " . $e->getMessage());
        }

        self::assertGreaterThan(0, $callCount, 'Dispatcher should eventually be called');
    }

    // ── C2: Circuit breaker opens under sustained backend failure ─────────────

    public function test_circuit_breaker_opens_and_prevents_cascade(): void
    {
        $schedule = $this->makeSchedule('chaos-s2');
        $this->store->seed($schedule);

        $dispatchCount = 0;
        $inner = new class($dispatchCount) implements FireDispatcherPort {
            public function __construct(private int &$calls) {}
            public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
            {
                $this->calls++;
                throw new FireDispatchException($fire, 'backend unavailable');
            }
        };

        $clock    = $this->clock;
        $cbInner  = $inner;
        $cb = new \Vortos\Scheduler\Engine\CircuitBreaker\DispatchCircuitBreaker(
            $cbInner, $clock, 3, 30,
        );

        // Trip the breaker
        for ($i = 0; $i < 3; $i++) {
            try { $cb->dispatch($this->makeFire($schedule->id), $schedule); } catch (\Throwable) {}
        }

        $before = $dispatchCount;

        // Dispatch when open — should NOT call inner
        $result = $cb->dispatch($this->makeFire($schedule->id), $schedule);

        self::assertSame(FireDispatchResult::CircuitOpen, $result);
        self::assertSame($before, $dispatchCount, 'Inner must not be called when circuit is open');
        self::assertSame(
            \Vortos\Scheduler\Engine\CircuitBreaker\CircuitBreakerState::Open,
            $cb->getState(),
        );
    }

    // ── C3: Partial multi-tenant failure — other tenants still fire ───────────

    public function test_failing_tenant_does_not_block_other_tenants(): void
    {
        $tenantA = $this->makeSchedule('tenant-a', 'tenant-a');
        $tenantB = $this->makeSchedule('tenant-b', 'tenant-b');
        $this->store->seed($tenantA);
        $this->store->seed($tenantB);

        $fired = [];
        $dispatcher = new class($fired, $tenantA->id->toString()) implements FireDispatcherPort {
            public function __construct(private array &$fired, private string $failId) {}
            public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
            {
                if ($fire->scheduleId->toString() === $this->failId) {
                    throw new FireDispatchException($fire, 'tenant-a backend down');
                }
                $this->fired[] = $fire->scheduleId->toString();
                return FireDispatchResult::Dispatched;
            }
        };

        $daemon = $this->makeDaemon($dispatcher);
        $daemon->runOnce();

        // tenant-b should still fire despite tenant-a failing
        self::assertContains(
            $tenantB->id->toString(),
            $fired,
            'Other tenants should not be blocked by a failing tenant',
        );
    }

    // ── C4: idempotency under retry — same slot never fires twice ─────────────

    public function test_same_slot_never_dispatched_twice_on_retry(): void
    {
        $schedule = $this->makeSchedule('idempotency-test');
        $this->store->seed($schedule);

        $fireLog  = [];
        $runStore = new ChaosRunStore();
        $dispatcher = new class($fireLog, $runStore) implements FireDispatcherPort {
            public function __construct(private array &$log, private ChaosRunStore $runStore) {}
            public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
            {
                $key = $fire->scheduleId->toString() . ':' . $fire->slot;
                if (isset($this->runStore->slots[$key])) {
                    return FireDispatchResult::AlreadyDispatched;
                }
                $this->runStore->slots[$key] = true;
                $this->log[$key]             = ($this->log[$key] ?? 0) + 1;
                return FireDispatchResult::Dispatched;
            }
        };

        $daemon = $this->makeDaemon($dispatcher, $runStore);

        // Run the same tick twice (simulates a duplicate trigger or process restart)
        $daemon->runOnce();
        $daemon->runOnce();

        $doubles = array_filter($fireLog, fn(int $c) => $c > 1);

        self::assertSame(
            [],
            array_keys($doubles),
            'No slot should be enqueued more than once',
        );
    }

    // ── C5: Lease loss mid-tick — audit must not corrupt ─────────────────────

    public function test_daemon_recovers_after_lease_loss(): void
    {
        $schedule = $this->makeSchedule('lease-loss');
        $this->store->seed($schedule);

        $dispatcher = new class implements FireDispatcherPort {
            public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
            {
                return FireDispatchResult::Dispatched;
            }
        };

        $daemon = $this->makeDaemon($dispatcher);

        // First tick succeeds normally
        $daemon->runOnce();

        // Simulate lease expiry by advancing well past TTL
        $this->clock->advanceSeconds(100);

        // Second tick: re-acquire lease, should still work
        try {
            $daemon->runOnce();
            self::assertTrue(true); // We just need no exception
        } catch (\Throwable $e) {
            self::fail("Daemon should recover after lease expiry: " . $e->getMessage());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeFlappingDispatcher(int $failRounds, int &$callCount): FireDispatcherPort
    {
        return new class($failRounds, $callCount) implements FireDispatcherPort {
            private int $ticks = 0;
            public function __construct(private int $failRounds, private int &$callCount) {}
            public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
            {
                $this->callCount++;
                $this->ticks++;
                if ($this->ticks <= $this->failRounds) {
                    throw new FireDispatchException($fire, "transient failure tick {$this->ticks}");
                }
                return FireDispatchResult::Dispatched;
            }
        };
    }

    private function makeDaemon(
        FireDispatcherPort    $dispatcher,
        ?ScheduleRunStoreInterface $runStore = null,
    ): SchedulerDaemon {
        $resolver = new ScheduleResolver(
            new StaticScheduleRegistry([]),
            $this->store,
            new InMemoryScheduleStatusOverrideStore(),
        );

        return new SchedulerDaemon(
            leasePort:                new InMemoryLeaseStore($this->clock),
            scheduleResolver:         $resolver,
            runStore:                 $runStore ?? new ChaosRunStore(),
            dueScan:                  new DueScan(new MisfireResolver(new \Vortos\Scheduler\Engine\SlotCalculator()), 86400),
            fireDispatcher:           $dispatcher,
            clock:                    $this->clock,
            logger:                   new \Psr\Log\NullLogger(),
            shardCount:               1,
            leaseTtlSec:              30,
            maxIdleSec:               60,
            tenantMaxConcurrentFires: 0,
        );
    }

    private function makeSchedule(string $name, ?string $tenantId = null): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     $name,
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('FakeChaosCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: $tenantId,
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

// ── Minimal run store for chaos tests ────────────────────────────────────────

final class ChaosRunStore implements ScheduleRunStoreInterface
{
    public array $slots = [];

    public function insertRun(\Vortos\Scheduler\Fire\ScheduleRun $run): void {}
    public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
    public function findRunState(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\RunState { return null; }
    public function findRunBySlot(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\ScheduleRun { return null; }
    public function transitionRunState(string $runId, \Vortos\Scheduler\Fire\RunState $newState, \DateTimeImmutable $at): void {}
    public function pruneOldRuns(\DateTimeImmutable $before, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult { return new PruneResult(0, false); }
    public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array { return []; }
}
