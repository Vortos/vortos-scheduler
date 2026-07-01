<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Registry;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Fire\CommandSpec;
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
use Vortos\Scheduler\Store\ScheduleStoreInterface;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;

/**
 * Unit tests for CachingScheduleResolver (E4).
 *
 * Verifies that the in-process TTL cache reduces store round-trips, and that
 * `invalidate()` forces a fresh load before the TTL has expired.
 */
final class CachingScheduleResolverTest extends TestCase
{
    private MutableClock $clock;
    private CountingScheduleStore $countingStore;
    private ScheduleResolver $inner;

    protected function setUp(): void
    {
        $this->clock        = new MutableClock(new DateTimeImmutable('2026-07-01T10:00:00Z', new DateTimeZone('UTC')));
        $this->countingStore = new CountingScheduleStore();
        $this->inner        = new ScheduleResolver(
            new StaticScheduleRegistry([]),
            $this->countingStore,
            new InMemoryScheduleStatusOverrideStore(),
        );
    }

    public function test_first_call_populates_cache_and_returns_schedules(): void
    {
        $schedule = $this->makeSchedule();
        $this->countingStore->seed($schedule);

        $caching = new CachingScheduleResolver($this->inner, $this->clock, 5);
        $result  = [...$caching->activeView()];

        self::assertCount(1, $result);
        self::assertSame($schedule->id->toString(), $result[0]->id->toString());
        self::assertSame(1, $this->countingStore->findAllActiveCalls);
    }

    public function test_second_call_within_ttl_returns_cached_result(): void
    {
        $this->countingStore->seed($this->makeSchedule());

        $caching = new CachingScheduleResolver($this->inner, $this->clock, 5);
        [...$caching->activeView()]; // prime cache
        [...$caching->activeView()]; // should hit cache

        self::assertSame(1, $this->countingStore->findAllActiveCalls, 'Store should only be queried once within TTL');
    }

    public function test_call_after_ttl_expiry_re_queries_store(): void
    {
        $this->countingStore->seed($this->makeSchedule());

        $caching = new CachingScheduleResolver($this->inner, $this->clock, 5);
        [...$caching->activeView()]; // prime cache

        // Advance past TTL
        $this->clock->advanceSeconds(6);
        [...$caching->activeView()]; // should re-query

        self::assertSame(2, $this->countingStore->findAllActiveCalls);
    }

    public function test_invalidate_forces_fresh_query_before_ttl(): void
    {
        $this->countingStore->seed($this->makeSchedule());

        $caching = new CachingScheduleResolver($this->inner, $this->clock, 60);
        [...$caching->activeView()]; // prime cache

        $caching->invalidate();

        [...$caching->activeView()]; // should re-query despite valid TTL

        self::assertSame(2, $this->countingStore->findAllActiveCalls);
    }

    public function test_ttl_zero_disables_cache(): void
    {
        $this->countingStore->seed($this->makeSchedule());

        $caching = new CachingScheduleResolver($this->inner, $this->clock, 0);
        [...$caching->activeView()];
        [...$caching->activeView()];
        [...$caching->activeView()];

        self::assertSame(3, $this->countingStore->findAllActiveCalls, 'TTL=0 must bypass cache on every call');
    }

    public function test_active_view_at_ttl_boundary_returns_cached(): void
    {
        $this->countingStore->seed($this->makeSchedule());

        $caching = new CachingScheduleResolver($this->inner, $this->clock, 5);
        [...$caching->activeView()]; // prime

        $this->clock->advanceSeconds(4); // Just under TTL
        [...$caching->activeView()];

        self::assertSame(1, $this->countingStore->findAllActiveCalls);
    }

    public function test_static_count_delegates_to_inner(): void
    {
        $caching = new CachingScheduleResolver($this->inner, $this->clock, 5);

        self::assertSame(0, $caching->staticCount());
    }

    public function test_has_static_schedules_delegates_to_inner(): void
    {
        $caching = new CachingScheduleResolver($this->inner, $this->clock, 5);

        self::assertFalse($caching->hasStaticSchedules());
    }

    public function test_get_registry_delegates_to_inner(): void
    {
        $caching = new CachingScheduleResolver($this->inner, $this->clock, 5);

        self::assertInstanceOf(StaticScheduleRegistry::class, $caching->getRegistry());
    }

    private function makeSchedule(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'cache-test',
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
}

// ── Counting store spy ────────────────────────────────────────────────────────

final class CountingScheduleStore implements \Vortos\Scheduler\Store\ScheduleStoreInterface
{
    public int $findAllActiveCalls = 0;

    private InMemoryScheduleStore $inner;

    public function __construct()
    {
        $this->inner = new InMemoryScheduleStore();
    }

    public function seed(\Vortos\Scheduler\Schedule\Schedule $schedule): void
    {
        $this->inner->seed($schedule);
    }

    public function save(\Vortos\Scheduler\Schedule\Schedule $schedule): void
    {
        $this->inner->save($schedule);
    }

    public function find(\Vortos\Scheduler\Schedule\ScheduleId $id, ?string $tenantId): ?\Vortos\Scheduler\Schedule\Schedule
    {
        return $this->inner->find($id, $tenantId);
    }

    public function findByName(string $name, ?string $tenantId): ?\Vortos\Scheduler\Schedule\Schedule
    {
        return $this->inner->findByName($name, $tenantId);
    }

    public function delete(\Vortos\Scheduler\Schedule\ScheduleId $id, ?string $tenantId): void
    {
        $this->inner->delete($id, $tenantId);
    }

    public function findActive(?string $tenantId): iterable
    {
        return $this->inner->findActive($tenantId);
    }

    public function findAllActive(): iterable
    {
        $this->findAllActiveCalls++;
        return $this->inner->findAllActive();
    }

    public function findAll(?string $tenantId): iterable
    {
        return $this->inner->findAll($tenantId);
    }
}
