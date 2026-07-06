<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Bench;

use DateTimeImmutable;
use DateTimeZone;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use Vortos\Scheduler\Audit\InMemorySchedulerAuditCheckpointRepository;
use Vortos\Scheduler\Audit\SchedulerAuditCheckpointProjector;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Engine\CircuitBreaker\DispatchCircuitBreaker;
use Vortos\Scheduler\Engine\DueScan;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Engine\FireDispatcherPort;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Observability\CardinalityGuardedSchedulerMetrics;
use Vortos\Scheduler\Observability\SchedulerMetrics;
use Vortos\Scheduler\Observability\SchedulerMetricsPort;
use Vortos\Scheduler\Registry\CachingScheduleResolver;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Schedule\Trigger\RecurringTrigger;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;

/**
 * Benchmarks for S11 production-hardening components.
 *
 * Run via:
 *   docker compose exec backend php vendor/bin/phpbench run packages/Vortos/src/Scheduler/Tests/Bench \
 *     --bootstrap=vendor/autoload.php --report=default
 */
#[Groups(['scheduler'])]
final class SchedulerBenchmark
{
    private MutableClock $clock;
    private InMemoryScheduleStore $store;
    private RecurringTrigger $hourlyTrigger;
    private RecurringTrigger $everyMinuteTrigger;
    private Schedule $schedule;
    private ScheduledFire $fire;
    private CachingScheduleResolver $cachingResolver;
    private ScheduleResolver $rawResolver;
    private CardinalityGuardedSchedulerMetrics $guardedMetrics;
    private SchedulerMetricsPort $nullMetricsPort;
    private SchedulerAuditCheckpointProjector $checkpointProjector;

    #[BeforeMethods('setUp')]
    public function setUp(): void
    {
        $this->clock              = new MutableClock(new DateTimeImmutable('2026-07-01T10:00:00Z', new DateTimeZone('UTC')));
        $this->store              = new InMemoryScheduleStore();
        $this->hourlyTrigger      = new RecurringTrigger('0 * * * *', new DateTimeZone('UTC'));
        $this->everyMinuteTrigger = new RecurringTrigger('* * * * *', new DateTimeZone('UTC'));

        $this->schedule = new Schedule(
            id:       ScheduleId::generate(),
            name:     'bench-schedule',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('BenchCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );

        $this->fire = new ScheduledFire(
            scheduleId:   $this->schedule->id,
            tenantId:     null,
            slot:         $this->schedule->id->toString() . ':2026-07-01T10:00:00+00:00',
            scheduledFor: new DateTimeImmutable('2026-07-01T10:00:00Z', new DateTimeZone('UTC')),
            attempt:      1,
        );

        for ($i = 0; $i < 200; $i++) {
            $this->store->seed(new Schedule(
                id:       ScheduleId::generate(),
                name:     "bench-sched-{$i}",
                source:   ScheduleSource::Dynamic,
                trigger:  new IntervalTrigger(3600),
                command:  new CommandSpec('BenchCommand'),
                misfire:  MisfirePolicy::skipMissed(),
                overlap:  OverlapPolicy::AllowConcurrent,
                timezone: new DateTimeZone('UTC'),
                jitter:   null,
                status:   ScheduleStatus::Active,
                tenantId: null,
            ));
        }

        $this->rawResolver = new ScheduleResolver(
            new StaticScheduleRegistry([]),
            $this->store,
            new InMemoryScheduleStatusOverrideStore(),
        );

        $this->cachingResolver = new CachingScheduleResolver($this->rawResolver, $this->clock, 5);

        $this->nullMetricsPort = new class implements SchedulerMetricsPort {
            public function recordFireResult(FireDispatchResult $result, string $scheduleId, ?string $tenantId): void {}
            public function recordMisfire(\Vortos\Scheduler\Schedule\Policy\MisfirePolicy $policy, string $scheduleId, ?string $tenantId): void {}
            public function recordDispatchLag(int $lagMs, string $scheduleId, ?string $tenantId): void {}
            public function recordLeaseContention(int $shardIndex): void {}
            public function recordLeaderAcquired(int $shardIndex): void {}
            public function recordLeaderLost(int $shardIndex): void {}
            public function recordActiveSchedules(int $count): void {}
            public function recordFairnessThrottle(?string $tenantId): void {}
            public function recordAuditFailure(string $eventType): void {}
            public function recordConsumeResult(bool $success, string $scheduleId, ?string $tenantId): void {}
            public function recordFireRequeued(string $reason, string $scheduleId, ?string $tenantId): void {}
            public function recordFireDeadLettered(string $reason, string $scheduleId, ?string $tenantId): void {}
            public function recordRunsPruned(int $count, ?string $tenantId): void {}
            public function recordFireQueuePruned(int $count): void {}
            public function recordPruneDuration(float $seconds, string $trigger): void {}
        };

        $this->guardedMetrics = new CardinalityGuardedSchedulerMetrics(
            $this->nullMetricsPort,
            null,
            200,
        );

        $this->checkpointProjector = new SchedulerAuditCheckpointProjector(
            new InMemorySchedulerAuditCheckpointRepository(),
            'bench-hmac-key',
            1000,
        );
    }

    // ── Trigger benchmarks ────────────────────────────────────────────────────

    #[Revs(1000), Iterations(3), Warmup(1)]
    public function benchCronNextRunAfterHourly(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:30:00Z', new DateTimeZone('UTC'));
        $this->hourlyTrigger->nextRunAfter($now);
    }

    #[Revs(1000), Iterations(3), Warmup(1)]
    public function benchCronNextRunAfterEveryMinute(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:30:45Z', new DateTimeZone('UTC'));
        $this->everyMinuteTrigger->nextRunAfter($now);
    }

    #[Revs(1000), Iterations(3), Warmup(1)]
    public function benchIntervalNextRunAfter(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:30:00Z', new DateTimeZone('UTC'));
        (new IntervalTrigger(3600))->nextRunAfter($now);
    }

    // ── CachingScheduleResolver benchmarks ───────────────────────────────────

    #[Revs(500), Iterations(3), Warmup(1)]
    public function benchCachingResolverCacheHit(): void
    {
        // First call primes the cache, subsequent calls hit it
        [...$this->cachingResolver->activeView()];
    }

    #[Revs(100), Iterations(3), Warmup(1)]
    public function benchCachingResolverCacheMiss(): void
    {
        $this->cachingResolver->invalidate();
        [...$this->cachingResolver->activeView()];
    }

    #[Revs(100), Iterations(3), Warmup(1)]
    public function benchRawResolverActiveView(): void
    {
        [...$this->rawResolver->activeView()];
    }

    // ── CardinalityGuardedSchedulerMetrics benchmarks ────────────────────────

    #[Revs(5000), Iterations(3), Warmup(1)]
    public function benchCardinalityGuardKnownId(): void
    {
        $this->guardedMetrics->recordFireResult(FireDispatchResult::Dispatched, 'sched-1', null);
    }

    #[Revs(5000), Iterations(3), Warmup(1)]
    public function benchCardinalityGuardOverflowId(): void
    {
        // Overflow path (cardinality already exhausted from setUp)
        $this->guardedMetrics->recordFireResult(FireDispatchResult::Dispatched, 'unknown-' . random_int(10000, 99999), null);
    }

    // ── Checkpoint projector benchmarks ──────────────────────────────────────

    #[Revs(1000), Iterations(3), Warmup(1)]
    public function benchCheckpointProjectorNoop(): void
    {
        // sequence 1 — not an epoch boundary
        $this->checkpointProjector->maybeCheckpoint('scheduler:system:bench', 1, hash('sha256', 'data'));
    }

    #[Revs(100), Iterations(3), Warmup(1)]
    public function benchCheckpointProjectorWrite(): void
    {
        // sequence at epoch boundary — writes a checkpoint
        $projector = new SchedulerAuditCheckpointProjector(
            new InMemorySchedulerAuditCheckpointRepository(),
            'bench-hmac-key',
            1,
        );
        $projector->maybeCheckpoint('scheduler:system:bench', 1, hash('sha256', 'data'));
    }

    // ── Circuit breaker benchmarks ────────────────────────────────────────────

    #[Revs(5000), Iterations(3), Warmup(1)]
    public function benchCircuitBreakerClosed(): void
    {
        $inner = new class implements FireDispatcherPort {
            public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
            {
                return FireDispatchResult::Dispatched;
            }
        };
        $cb = new DispatchCircuitBreaker($inner, $this->clock, 5, 30);
        $cb->dispatch($this->fire, $this->schedule);
    }

    #[Revs(5000), Iterations(3), Warmup(1)]
    public function benchCircuitBreakerOpen(): void
    {
        $inner = new class implements FireDispatcherPort {
            public function dispatch(ScheduledFire $fire, Schedule $schedule): FireDispatchResult
            {
                throw new \Vortos\Scheduler\Engine\Exception\FireDispatchException($fire, 'x');
            }
        };
        $cb = new DispatchCircuitBreaker($inner, $this->clock, 5, 30);

        // Trip the breaker
        for ($i = 0; $i < 5; $i++) {
            try { $cb->dispatch($this->fire, $this->schedule); } catch (\Throwable) {}
        }

        // Benchmark the open-circuit fast-path
        $cb->dispatch($this->fire, $this->schedule);
    }

    // ── DueScan benchmark ─────────────────────────────────────────────────────

    #[Revs(50), Iterations(3), Warmup(1)]
    public function benchDueScan200Schedules(): void
    {
        $schedules = [...$this->rawResolver->activeView()];
        $dueScan   = new DueScan(new MisfireResolver(), 86400);
        $now       = $this->clock->now();

        $dueScan->compute($schedules, [], $now);
    }
}
