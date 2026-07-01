<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Registry;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Store\Exception\ScheduleNameCollisionException;
use Vortos\Scheduler\Store\ScheduleStoreInterface;

/**
 * Unit tests for ScheduleResolver.
 *
 * Verifies:
 *  - Empty registry + empty store yields nothing
 *  - Static schedules yielded before dynamic schedules
 *  - Paused / Disabled statics are filtered out
 *  - Active dynamics are yielded without collision
 *  - System-scoped (tenantId=null) dynamic with same name as static → throws collision
 *  - Tenant-scoped dynamic with same name as static → NOT a collision (different namespace)
 *  - Dynamic sharing an ID with a static → throws collision
 *  - staticCount() / hasStaticSchedules() / getRegistry() accessors
 */
final class ScheduleResolverTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // Empty cases
    // ─────────────────────────────────────────────────────────────

    public function test_empty_registry_and_empty_store_yields_nothing(): void
    {
        $resolver = $this->makeResolver([], []);

        self::assertSame([], \iterator_to_array($resolver->activeView()));
    }

    public function test_static_count_is_zero_with_empty_registry(): void
    {
        self::assertSame(0, $this->makeResolver([], [])->staticCount());
    }

    public function test_has_static_schedules_false_when_empty(): void
    {
        self::assertFalse($this->makeResolver([], [])->hasStaticSchedules());
    }

    // ─────────────────────────────────────────────────────────────
    // Static schedules — Active only
    // ─────────────────────────────────────────────────────────────

    public function test_active_static_schedule_is_yielded(): void
    {
        $resolver  = $this->makeResolver([RsvActiveFixture::class], []);
        $schedules = \iterator_to_array($resolver->activeView());

        self::assertCount(1, $schedules);
        self::assertSame(RsvActiveFixture::NAME, $schedules[0]->name);
    }

    public function test_paused_static_schedule_is_not_yielded(): void
    {
        $resolver  = $this->makeResolver([RsvPausedFixture::class], []);
        $schedules = \iterator_to_array($resolver->activeView());

        self::assertCount(0, $schedules);
    }

    public function test_disabled_static_schedule_is_not_yielded(): void
    {
        $resolver  = $this->makeResolver([RsvDisabledFixture::class], []);
        $schedules = \iterator_to_array($resolver->activeView());

        self::assertCount(0, $schedules);
    }

    public function test_static_count_includes_all_statuses(): void
    {
        // staticCount() counts ALL statics regardless of status (it calls all()).
        $resolver = $this->makeResolver([RsvActiveFixture::class, RsvPausedFixture::class], []);
        self::assertSame(2, $resolver->staticCount());
    }

    public function test_has_static_schedules_true_when_registry_not_empty(): void
    {
        self::assertTrue($this->makeResolver([RsvActiveFixture::class], [])->hasStaticSchedules());
    }

    // ─────────────────────────────────────────────────────────────
    // Dynamic schedules
    // ─────────────────────────────────────────────────────────────

    public function test_dynamic_schedules_are_yielded_when_no_statics(): void
    {
        $dynamic  = $this->makeDynamic('dynamic-only', 'tenant-a');
        $resolver = $this->makeResolver([], [$dynamic]);
        $result   = \iterator_to_array($resolver->activeView());

        self::assertCount(1, $result);
        self::assertSame('dynamic-only', $result[0]->name);
    }

    // ─────────────────────────────────────────────────────────────
    // Ordering: statics first, then dynamics
    // ─────────────────────────────────────────────────────────────

    public function test_statics_yielded_before_dynamics(): void
    {
        $dynamic  = $this->makeDynamic('dynamic-after', 'tenant-b');
        $resolver = $this->makeResolver([RsvActiveFixture::class], [$dynamic]);
        $result   = \iterator_to_array($resolver->activeView());

        self::assertCount(2, $result);
        self::assertSame(RsvActiveFixture::NAME, $result[0]->name, 'static must come first');
        self::assertSame('dynamic-after', $result[1]->name, 'dynamic must come second');
    }

    // ─────────────────────────────────────────────────────────────
    // Name collision detection (system-scoped)
    // ─────────────────────────────────────────────────────────────

    public function test_system_scoped_dynamic_same_name_as_static_throws_collision(): void
    {
        $dynamic = $this->makeDynamic(RsvActiveFixture::NAME, null); // tenantId=null = system-scoped
        $resolver = $this->makeResolver([RsvActiveFixture::class], [$dynamic]);

        $this->expectException(ScheduleNameCollisionException::class);

        \iterator_to_array($resolver->activeView());
    }

    public function test_tenant_scoped_dynamic_same_name_as_static_is_not_collision(): void
    {
        // A tenant-scoped dynamic share the name with a static — NOT a collision
        // because name uniqueness is tenant-namespaced.
        $dynamic  = $this->makeDynamic(RsvActiveFixture::NAME, 'tenant-x');
        $resolver = $this->makeResolver([RsvActiveFixture::class], [$dynamic]);
        $result   = \iterator_to_array($resolver->activeView());

        // Both yielded without throwing.
        self::assertCount(2, $result);
    }

    // ─────────────────────────────────────────────────────────────
    // ID collision detection
    // ─────────────────────────────────────────────────────────────

    public function test_dynamic_with_same_id_as_static_throws_collision(): void
    {
        // Build the static schedule to get its ID.
        $staticSchedule = RsvActiveFixture::build();
        $dynamic        = new Schedule(
            id:       $staticSchedule->id,    // same ID — collision
            name:     'different-name',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\IdCollision'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: 'tenant-c',
        );

        $resolver = $this->makeResolver([RsvActiveFixture::class], [$dynamic]);

        $this->expectException(ScheduleNameCollisionException::class);

        \iterator_to_array($resolver->activeView());
    }

    // ─────────────────────────────────────────────────────────────
    // getRegistry() accessor
    // ─────────────────────────────────────────────────────────────

    public function test_get_registry_returns_the_injected_registry(): void
    {
        $registry = new StaticScheduleRegistry([RsvActiveFixture::class]);
        $resolver = new ScheduleResolver($registry, $this->emptyStore());

        self::assertSame($registry, $resolver->getRegistry());
    }

    // ─────────────────────────────────────────────────────────────
    // Collision on second dynamic (first passes, second fails)
    // ─────────────────────────────────────────────────────────────

    public function test_collision_on_second_dynamic_first_is_yielded(): void
    {
        $safe    = $this->makeDynamic('safe-dynamic', 'tenant-d');
        $bad     = $this->makeDynamic(RsvActiveFixture::NAME, null); // collision
        $resolver = $this->makeResolver([RsvActiveFixture::class], [$safe, $bad]);

        $this->expectException(ScheduleNameCollisionException::class);

        // Consume the generator fully — will throw when it reaches $bad.
        \iterator_to_array($resolver->activeView());
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────

    /** @param list<class-string<StaticScheduleDefinition>> $definitionClasses */
    /** @param list<Schedule> $dynamics */
    private function makeResolver(array $definitionClasses, array $dynamics): ScheduleResolver
    {
        $registry = new StaticScheduleRegistry($definitionClasses);
        $store    = $this->storeWith($dynamics);

        return new ScheduleResolver($registry, $store);
    }

    private function makeDynamic(string $name, ?string $tenantId): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     $name,
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\Dynamic'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: $tenantId,
        );
    }

    /** @param list<Schedule> $schedules */
    private function storeWith(array $schedules): ScheduleStoreInterface
    {
        return new class($schedules) implements ScheduleStoreInterface {
            public function __construct(private readonly array $schedules) {}

            public function save(Schedule $s): void {}

            public function find(ScheduleId $id, ?string $tenantId): ?Schedule
            {
                return null;
            }

            public function findByName(string $name, ?string $tenantId): ?Schedule
            {
                return null;
            }

            public function delete(ScheduleId $id, ?string $tenantId): void {}

            public function findActive(?string $tenantId): iterable
            {
                return [];
            }

            public function findAllActive(): iterable
            {
                return $this->schedules;
            }

            public function findAll(?string $tenantId): iterable
            {
                return [];
            }
        };
    }

    private function emptyStore(): ScheduleStoreInterface
    {
        return $this->storeWith([]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures (same namespace, unique names via Rsv prefix)
// ─────────────────────────────────────────────────────────────────────────────

final class RsvActiveFixture implements StaticScheduleDefinition
{
    public const NAME = 'rsv-active-fixture';
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     self::NAME,
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\RsvActive'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

final class RsvPausedFixture implements StaticScheduleDefinition
{
    public const NAME = 'rsv-paused-fixture';
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     self::NAME,
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\RsvPaused'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Paused,
            tenantId: null,
        );
    }
}

final class RsvDisabledFixture implements StaticScheduleDefinition
{
    public const NAME = 'rsv-disabled-fixture';
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     self::NAME,
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\RsvDisabled'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Disabled,
            tenantId: null,
        );
    }
}
