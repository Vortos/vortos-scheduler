<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Registry;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;

/**
 * Unit tests for StaticScheduleRegistry.
 *
 * Verifies:
 *  - Empty registry behaviours
 *  - Lazy build() cache (build() called exactly once)
 *  - all(), isEmpty(), findById(), findByName()
 *  - Defence-in-depth guard: rejects tenantId!=null or source!=Static from build()
 *  - Multiple definition ordering
 */
final class StaticScheduleRegistryTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // Empty registry
    // ─────────────────────────────────────────────────────────────

    public function test_empty_registry_all_returns_empty_array(): void
    {
        self::assertSame([], (new StaticScheduleRegistry([]))->all());
    }

    public function test_default_constructor_is_empty(): void
    {
        self::assertTrue((new StaticScheduleRegistry())->isEmpty());
    }

    public function test_empty_registry_find_by_id_returns_null(): void
    {
        self::assertNull((new StaticScheduleRegistry())->findById('does-not-exist'));
    }

    public function test_empty_registry_find_by_name_returns_null(): void
    {
        self::assertNull((new StaticScheduleRegistry())->findByName('does-not-exist'));
    }

    // ─────────────────────────────────────────────────────────────
    // Single definition
    // ─────────────────────────────────────────────────────────────

    public function test_registry_with_one_definition_all_has_one_element(): void
    {
        $registry = new StaticScheduleRegistry([StaticRegFixtureA::class]);
        self::assertCount(1, $registry->all());
    }

    public function test_registry_with_definition_is_not_empty(): void
    {
        self::assertFalse((new StaticScheduleRegistry([StaticRegFixtureA::class]))->isEmpty());
    }

    public function test_all_returns_schedule_with_correct_name(): void
    {
        $registry  = new StaticScheduleRegistry([StaticRegFixtureA::class]);
        $schedules = $registry->all();

        self::assertSame(StaticRegFixtureA::NAME, $schedules[0]->name);
    }

    public function test_find_by_id_finds_existing_schedule(): void
    {
        $registry  = new StaticScheduleRegistry([StaticRegFixtureA::class]);
        $schedule  = $registry->all()[0];

        self::assertSame($schedule, $registry->findById($schedule->id->toString()));
    }

    public function test_find_by_id_returns_null_for_unknown_id(): void
    {
        $registry = new StaticScheduleRegistry([StaticRegFixtureA::class]);
        self::assertNull($registry->findById('ffffffff-ffff-ffff-ffff-ffffffffffff'));
    }

    public function test_find_by_name_finds_existing_schedule(): void
    {
        $registry = new StaticScheduleRegistry([StaticRegFixtureA::class]);
        $schedule = $registry->all()[0];

        self::assertSame($schedule, $registry->findByName($schedule->name));
    }

    public function test_find_by_name_returns_null_for_unknown_name(): void
    {
        $registry = new StaticScheduleRegistry([StaticRegFixtureA::class]);
        self::assertNull($registry->findByName('completely-unknown'));
    }

    // ─────────────────────────────────────────────────────────────
    // Lazy cache: build() called exactly once per registry instance
    // ─────────────────────────────────────────────────────────────

    public function test_build_is_called_exactly_once_across_all_calls(): void
    {
        StaticRegCountingFixture::reset();

        $registry = new StaticScheduleRegistry([StaticRegCountingFixture::class]);

        $registry->all();
        $registry->all();
        $registry->all();
        $registry->isEmpty();
        $registry->findByName('counting-fixture');

        self::assertSame(1, StaticRegCountingFixture::$callCount, 'build() must be called once; registry must cache the result');
    }

    // ─────────────────────────────────────────────────────────────
    // Multiple definitions
    // ─────────────────────────────────────────────────────────────

    public function test_multiple_definitions_all_returned(): void
    {
        $registry  = new StaticScheduleRegistry([StaticRegFixtureA::class, StaticRegFixtureB::class]);
        $schedules = $registry->all();

        self::assertCount(2, $schedules);
    }

    public function test_multiple_definitions_order_is_preserved(): void
    {
        $registry  = new StaticScheduleRegistry([StaticRegFixtureA::class, StaticRegFixtureB::class]);
        $schedules = $registry->all();

        self::assertSame(StaticRegFixtureA::NAME, $schedules[0]->name);
        self::assertSame(StaticRegFixtureB::NAME, $schedules[1]->name);
    }

    public function test_find_by_name_works_for_second_definition(): void
    {
        $registry = new StaticScheduleRegistry([StaticRegFixtureA::class, StaticRegFixtureB::class]);
        $found    = $registry->findByName(StaticRegFixtureB::NAME);

        self::assertNotNull($found);
        self::assertSame(StaticRegFixtureB::NAME, $found->name);
    }

    // ─────────────────────────────────────────────────────────────
    // Defence-in-depth guard (tenantId / source violations)
    // ─────────────────────────────────────────────────────────────

    public function test_guard_throws_logic_exception_on_non_null_tenant_id(): void
    {
        $registry = new StaticScheduleRegistry([StaticRegTenantViolation::class]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/tenantId/');

        $registry->all();
    }

    public function test_guard_throws_logic_exception_on_wrong_source(): void
    {
        $registry = new StaticScheduleRegistry([StaticRegSourceViolation::class]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/source/');

        $registry->all();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures (same namespace, unique class names)
// ─────────────────────────────────────────────────────────────────────────────

final class StaticRegFixtureA implements StaticScheduleDefinition
{
    public const NAME = 'static-reg-fixture-a';
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     self::NAME,
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\RegFixtureA'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

final class StaticRegFixtureB implements StaticScheduleDefinition
{
    public const NAME = 'static-reg-fixture-b';
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     self::NAME,
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(120),
            command:  new CommandSpec('App\\Command\\RegFixtureB'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

final class StaticRegCountingFixture implements StaticScheduleDefinition
{
    public static int $callCount = 0;

    public static function reset(): void
    {
        self::$callCount = 0;
    }

    public static function build(): Schedule
    {
        self::$callCount++;

        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'counting-fixture',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\Counting'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

final class StaticRegTenantViolation implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'reg-tenant-violation',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\RegTenantViolation'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: 'must-be-null',
        );
    }
}

final class StaticRegSourceViolation implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'reg-source-violation',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\RegSourceViolation'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}
