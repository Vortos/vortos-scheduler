<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\DependencyInjection;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Scheduler\DependencyInjection\Compiler\StaticSchedulePass;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Attribute\Scheduled;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Schedule\Trigger\OneShotTrigger;
use Vortos\Scheduler\Schedule\Trigger\Trigger;

/**
 * Unit tests for StaticSchedulePass.
 *
 * All tests exercise process() via ContainerBuilder to match real container-build conditions.
 *
 * Verifies:
 *  - TAG constant value
 *  - No tagged services → empty registry registered
 *  - Valid tagged service → class FQCN registered in registry
 *  - Multiple valid services → all FQCNs registered
 *  - Non-existent class → RuntimeException
 *  - Missing #[Scheduled] attribute → RuntimeException
 *  - Implements interface but no attribute → RuntimeException
 *  - build() throws → RuntimeException (wrapping original)
 *  - tenantId != null → RuntimeException
 *  - source != Static → RuntimeException
 *  - Non-OneShotTrigger returning null → RuntimeException
 *  - OneShotTrigger past fire time → accepted (no exception)
 *  - Duplicate static name → RuntimeException
 *  - Duplicate static ID → RuntimeException
 */
final class StaticSchedulePassTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // TAG constant
    // ─────────────────────────────────────────────────────────────

    public function test_tag_constant_value(): void
    {
        self::assertSame('vortos_scheduler.static_schedule', StaticSchedulePass::TAG);
    }

    // ─────────────────────────────────────────────────────────────
    // No tagged services
    // ─────────────────────────────────────────────────────────────

    public function test_no_tagged_services_registers_empty_registry(): void
    {
        $container = $this->containerWithRegistry();

        (new StaticSchedulePass())->process($container);

        $def = $container->getDefinition(StaticScheduleRegistry::class);
        self::assertSame([], $def->getArgument('$definitionClasses'));
    }

    // ─────────────────────────────────────────────────────────────
    // Valid services
    // ─────────────────────────────────────────────────────────────

    public function test_valid_tagged_service_is_registered_in_registry(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassValidFixtureA::class, PassValidFixtureA::class)
            ->addTag(StaticSchedulePass::TAG);

        (new StaticSchedulePass())->process($container);

        $classes = $container->getDefinition(StaticScheduleRegistry::class)
            ->getArgument('$definitionClasses');

        self::assertContains(PassValidFixtureA::class, $classes);
    }

    public function test_multiple_valid_services_all_registered(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassValidFixtureA::class, PassValidFixtureA::class)
            ->addTag(StaticSchedulePass::TAG);
        $container->register(PassValidFixtureB::class, PassValidFixtureB::class)
            ->addTag(StaticSchedulePass::TAG);

        (new StaticSchedulePass())->process($container);

        $classes = $container->getDefinition(StaticScheduleRegistry::class)
            ->getArgument('$definitionClasses');

        self::assertCount(2, $classes);
        self::assertContains(PassValidFixtureA::class, $classes);
        self::assertContains(PassValidFixtureB::class, $classes);
    }

    // ─────────────────────────────────────────────────────────────
    // Error: class does not exist
    // ─────────────────────────────────────────────────────────────

    public function test_non_existent_class_throws_runtime_exception(): void
    {
        $container = $this->containerWithRegistry();
        $container->register('test.ghost.service', 'NonExistent\Schedule\GhostClass')
            ->addTag(StaticSchedulePass::TAG);

        $this->expectException(\RuntimeException::class);

        (new StaticSchedulePass())->process($container);
    }

    // ─────────────────────────────────────────────────────────────
    // Error: missing #[Scheduled] attribute
    // ─────────────────────────────────────────────────────────────

    public function test_missing_scheduled_attribute_throws_runtime_exception(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassNoAttributeFixture::class, PassNoAttributeFixture::class)
            ->addTag(StaticSchedulePass::TAG);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/#\[Scheduled\]/');

        (new StaticSchedulePass())->process($container);
    }

    // ─────────────────────────────────────────────────────────────
    // Error: has attribute but doesn't implement interface
    // ─────────────────────────────────────────────────────────────

    public function test_missing_interface_throws_runtime_exception(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassNoInterfaceFixture::class, PassNoInterfaceFixture::class)
            ->addTag(StaticSchedulePass::TAG);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/StaticScheduleDefinition/');

        (new StaticSchedulePass())->process($container);
    }

    // ─────────────────────────────────────────────────────────────
    // Error: build() throws
    // ─────────────────────────────────────────────────────────────

    public function test_build_throws_wrapped_in_runtime_exception(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassBuildThrowsFixture::class, PassBuildThrowsFixture::class)
            ->addTag(StaticSchedulePass::TAG);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/threw during build/');

        (new StaticSchedulePass())->process($container);
    }

    // ─────────────────────────────────────────────────────────────
    // Error: tenantId != null
    // ─────────────────────────────────────────────────────────────

    public function test_non_null_tenant_id_throws_runtime_exception(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassTenantViolationFixture::class, PassTenantViolationFixture::class)
            ->addTag(StaticSchedulePass::TAG);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tenantId/');

        (new StaticSchedulePass())->process($container);
    }

    // ─────────────────────────────────────────────────────────────
    // Error: source != Static
    // ─────────────────────────────────────────────────────────────

    public function test_wrong_source_throws_runtime_exception(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassSourceViolationFixture::class, PassSourceViolationFixture::class)
            ->addTag(StaticSchedulePass::TAG);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/source/');

        (new StaticSchedulePass())->process($container);
    }

    // ─────────────────────────────────────────────────────────────
    // Error: non-OneShotTrigger returns null from nextRunAfter()
    // ─────────────────────────────────────────────────────────────

    public function test_null_returning_trigger_throws_runtime_exception(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassNullTriggerFixture::class, PassNullTriggerFixture::class)
            ->addTag(StaticSchedulePass::TAG);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nextRunAfter\(now\) returned null/');

        (new StaticSchedulePass())->process($container);
    }

    // ─────────────────────────────────────────────────────────────
    // Accepted: OneShotTrigger past its fire time
    // ─────────────────────────────────────────────────────────────

    public function test_one_shot_trigger_past_fire_time_is_accepted(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassPastOneShotFixture::class, PassPastOneShotFixture::class)
            ->addTag(StaticSchedulePass::TAG);

        // Must not throw.
        (new StaticSchedulePass())->process($container);

        $classes = $container->getDefinition(StaticScheduleRegistry::class)
            ->getArgument('$definitionClasses');

        self::assertContains(PassPastOneShotFixture::class, $classes);
    }

    // ─────────────────────────────────────────────────────────────
    // Error: duplicate name
    // ─────────────────────────────────────────────────────────────

    public function test_duplicate_name_throws_runtime_exception(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassDuplicateNameA::class, PassDuplicateNameA::class)
            ->addTag(StaticSchedulePass::TAG);
        $container->register(PassDuplicateNameB::class, PassDuplicateNameB::class)
            ->addTag(StaticSchedulePass::TAG);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Dd]uplicate.*name/');

        (new StaticSchedulePass())->process($container);
    }

    // ─────────────────────────────────────────────────────────────
    // Error: duplicate ID
    // ─────────────────────────────────────────────────────────────

    public function test_duplicate_id_throws_runtime_exception(): void
    {
        $container = $this->containerWithRegistry();
        $container->register(PassDuplicateIdA::class, PassDuplicateIdA::class)
            ->addTag(StaticSchedulePass::TAG);
        $container->register(PassDuplicateIdB::class, PassDuplicateIdB::class)
            ->addTag(StaticSchedulePass::TAG);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Dd]uplicate.*[Ii][Dd]/');

        (new StaticSchedulePass())->process($container);
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────

    private function containerWithRegistry(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(StaticScheduleRegistry::class, StaticScheduleRegistry::class)
            ->setArgument('$definitionClasses', [])
            ->setPublic(false);

        return $container;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures (must be named, not anonymous, to carry PHP attributes)
// ─────────────────────────────────────────────────────────────────────────────

#[Scheduled]
final class PassValidFixtureA implements StaticScheduleDefinition
{
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     'pass-valid-fixture-a',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\PassA'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

#[Scheduled]
final class PassValidFixtureB implements StaticScheduleDefinition
{
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     'pass-valid-fixture-b',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(120),
            command:  new CommandSpec('App\\Command\\PassB'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

// No #[Scheduled] attribute — implements interface but missing attribute.
final class PassNoAttributeFixture implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'pass-no-attr',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\NoAttr'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

// Has #[Scheduled] but does NOT implement StaticScheduleDefinition.
#[Scheduled]
final class PassNoInterfaceFixture
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'pass-no-interface',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\NoInterface'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

#[Scheduled]
final class PassBuildThrowsFixture implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        throw new \RuntimeException('build() exploded intentionally');
    }
}

#[Scheduled]
final class PassTenantViolationFixture implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'pass-tenant-violation',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\TenantViolation'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: 'must-be-null',
        );
    }
}

#[Scheduled]
final class PassSourceViolationFixture implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'pass-source-violation',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\SourceViolation'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

/** A trigger that always returns null from nextRunAfter() — NOT a OneShotTrigger. */
final class AlwaysNullTrigger implements Trigger
{
    public function nextRunAfter(DateTimeImmutable $after): ?DateTimeImmutable
    {
        return null;
    }

    public function describe(): string
    {
        return 'always-null';
    }
}

#[Scheduled]
final class PassNullTriggerFixture implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'pass-null-trigger',
            source:   ScheduleSource::Static,
            trigger:  new AlwaysNullTrigger(),
            command:  new CommandSpec('App\\Command\\NullTrigger'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

#[Scheduled]
final class PassPastOneShotFixture implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'pass-past-one-shot',
            source:   ScheduleSource::Static,
            trigger:  new OneShotTrigger(new DateTimeImmutable('2000-01-01T00:00:00Z')),
            command:  new CommandSpec('App\\Command\\PastOneShot'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

#[Scheduled]
final class PassDuplicateNameA implements StaticScheduleDefinition
{
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     'duplicate-schedule-name',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\DupNameA'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

#[Scheduled]
final class PassDuplicateNameB implements StaticScheduleDefinition
{
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     'duplicate-schedule-name', // same name as A
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\DupNameB'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

// Shared ID for PassDuplicateIdA and PassDuplicateIdB.
final class PassSharedId
{
    private static ?ScheduleId $id = null;

    public static function get(): ScheduleId
    {
        return self::$id ??= ScheduleId::generate();
    }
}

#[Scheduled]
final class PassDuplicateIdA implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       PassSharedId::get(),
            name:     'dup-id-fixture-a',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\DupIdA'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

#[Scheduled]
final class PassDuplicateIdB implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       PassSharedId::get(), // same ID as A
            name:     'dup-id-fixture-b',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\DupIdB'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}
