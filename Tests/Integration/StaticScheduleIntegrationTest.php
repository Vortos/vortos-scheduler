<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Vortos\Scheduler\DependencyInjection\Compiler\StaticSchedulePass;
use Vortos\Scheduler\DependencyInjection\SchedulerExtension;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\ScheduleResolver;
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
use Vortos\Scheduler\Store\Exception\ScheduleNameCollisionException;
use Vortos\Scheduler\Store\ScheduleStoreInterface;

/**
 * End-to-end integration tests for the S6 static schedule discovery pipeline.
 *
 * Exercises the full path: ContainerBuilder → SchedulerExtension → StaticSchedulePass
 * → StaticScheduleRegistry → ScheduleResolver.
 *
 * Tests compile-time collision detection, resolver ordering, and the daemon's
 * integration with the resolver.
 *
 * Covers:
 *  - Container build: one valid definition → registry contains it
 *  - Container build: multiple valid definitions → all registered
 *  - Registry::all() correctly builds schedules after container compile
 *  - Resolver::activeView() yields static before dynamic
 *  - Compile-time collision: duplicate name → container build fails
 *  - Compile-time collision: duplicate ID → container build fails
 *  - Compile-time: missing #[Scheduled] attribute → container build fails
 *  - Past OneShotTrigger accepted at compile time
 *  - Resolver collision at runtime (dynamic vs static same name/id)
 */
final class StaticScheduleIntegrationTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // Successful container builds
    // ─────────────────────────────────────────────────────────────

    public function test_valid_definition_is_in_registry_after_container_compile(): void
    {
        $container = $this->buildContainer([IntStaticA::class]);
        $registry  = $container->get(StaticScheduleRegistry::class);

        self::assertCount(1, $registry->all());
        self::assertSame(IntStaticA::NAME, $registry->all()[0]->name);
    }

    public function test_multiple_valid_definitions_all_discovered(): void
    {
        $container = $this->buildContainer([IntStaticA::class, IntStaticB::class]);
        $registry  = $container->get(StaticScheduleRegistry::class);

        self::assertCount(2, $registry->all());

        $names = array_map(fn (Schedule $s) => $s->name, $registry->all());
        self::assertContains(IntStaticA::NAME, $names);
        self::assertContains(IntStaticB::NAME, $names);
    }

    public function test_registry_all_returns_correct_schedule_objects(): void
    {
        $container = $this->buildContainer([IntStaticA::class]);
        $registry  = $container->get(StaticScheduleRegistry::class);

        $schedule = $registry->all()[0];

        self::assertSame(ScheduleSource::Static, $schedule->source);
        self::assertNull($schedule->tenantId);
        self::assertSame(ScheduleStatus::Active, $schedule->status);
    }

    public function test_past_one_shot_trigger_accepted_at_compile_time(): void
    {
        // Container should build without throwing for a past-time OneShotTrigger.
        $container = $this->buildContainer([IntPastOneShotStatic::class]);
        $registry  = $container->get(StaticScheduleRegistry::class);

        self::assertCount(1, $registry->all());
    }

    // ─────────────────────────────────────────────────────────────
    // Resolver: statics yielded before dynamics
    // ─────────────────────────────────────────────────────────────

    public function test_resolver_yields_static_before_dynamic(): void
    {
        $container = $this->buildContainer([IntStaticA::class]);
        $registry  = $container->get(StaticScheduleRegistry::class);

        $dynamic = new Schedule(
            id:       ScheduleId::generate(),
            name:     'int-dynamic-schedule',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\IntDynamic'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: 'tenant-z',
        );

        $resolver  = new ScheduleResolver($registry, $this->storeWith([$dynamic]));
        $schedules = \iterator_to_array($resolver->activeView());

        self::assertCount(2, $schedules);
        self::assertSame(IntStaticA::NAME, $schedules[0]->name, 'static must be first');
        self::assertSame('int-dynamic-schedule', $schedules[1]->name, 'dynamic must be second');
    }

    // ─────────────────────────────────────────────────────────────
    // Compile-time failure: duplicate name
    // ─────────────────────────────────────────────────────────────

    public function test_duplicate_static_name_causes_container_build_failure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Dd]uplicate.*name/');

        $this->buildContainer([IntDuplicateNameX::class, IntDuplicateNameY::class]);
    }

    // ─────────────────────────────────────────────────────────────
    // Compile-time failure: duplicate ID
    // ─────────────────────────────────────────────────────────────

    public function test_duplicate_static_id_causes_container_build_failure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/[Dd]uplicate.*[Ii][Dd]/');

        $this->buildContainer([IntDuplicateIdX::class, IntDuplicateIdY::class]);
    }

    // ─────────────────────────────────────────────────────────────
    // Compile-time failure: missing #[Scheduled]
    // ─────────────────────────────────────────────────────────────

    public function test_missing_attribute_causes_container_build_failure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/#\[Scheduled\]/');

        $this->buildContainer([IntNoAttributeStatic::class]);
    }

    // ─────────────────────────────────────────────────────────────
    // Runtime: dynamic-static name collision detected by resolver
    // ─────────────────────────────────────────────────────────────

    public function test_runtime_name_collision_throws_from_resolver(): void
    {
        $container = $this->buildContainer([IntStaticA::class]);
        $registry  = $container->get(StaticScheduleRegistry::class);

        $collidingDynamic = new Schedule(
            id:       ScheduleId::generate(),
            name:     IntStaticA::NAME,   // same name
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\IntNameCollision'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,               // system-scoped → collision
        );

        $resolver = new ScheduleResolver($registry, $this->storeWith([$collidingDynamic]));

        $this->expectException(ScheduleNameCollisionException::class);
        \iterator_to_array($resolver->activeView());
    }

    // ─────────────────────────────────────────────────────────────
    // Infrastructure
    // ─────────────────────────────────────────────────────────────

    /**
     * @param list<class-string> $staticClasses
     */
    private function buildContainer(array $staticClasses): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // SchedulerExtension::load() hard-requires these (same convention as
        // CacheExtension, AuthExtension, ... — see CacheExtensionEnvDefaultsTest).
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/missing_vortos_scheduler_config');
        $container->setParameter('kernel.env', 'test');

        // ScheduleService (the package facade) is public and its dispatch chain now
        // survives compilation — that chain constructor-injects Connection, so a
        // definition must exist even though these tests never instantiate it.
        $container->register(Connection::class, Connection::class)->setPublic(false);
        $container->register(LoggerInterface::class, NullLogger::class)->setPublic(false);

        (new SchedulerExtension())->load([], $container);

        foreach ($staticClasses as $class) {
            $container->register($class, $class)
                ->setPublic(true)
                ->addTag(StaticSchedulePass::TAG);
        }

        if (!$container->hasDefinition(StaticScheduleRegistry::class)) {
            $container->register(StaticScheduleRegistry::class, StaticScheduleRegistry::class)
                ->setArgument('$definitionClasses', [])
                ->setPublic(true);
        } else {
            $container->getDefinition(StaticScheduleRegistry::class)->setPublic(true);
        }

        $container->addCompilerPass(new StaticSchedulePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -33);

        $container->compile();

        return $container;
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
}

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures
// ─────────────────────────────────────────────────────────────────────────────

#[Scheduled]
final class IntStaticA implements StaticScheduleDefinition
{
    public const NAME = 'int-static-a';
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     self::NAME,
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\IntStaticA'),
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
final class IntStaticB implements StaticScheduleDefinition
{
    public const NAME = 'int-static-b';
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     self::NAME,
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(120),
            command:  new CommandSpec('App\\Command\\IntStaticB'),
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
final class IntPastOneShotStatic implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'int-past-one-shot',
            source:   ScheduleSource::Static,
            trigger:  new OneShotTrigger(new DateTimeImmutable('2020-01-01T00:00:00Z')),
            command:  new CommandSpec('App\\Command\\IntPastOneShot'),
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
final class IntDuplicateNameX implements StaticScheduleDefinition
{
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     'int-colliding-name',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\IntDupNameX'),
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
final class IntDuplicateNameY implements StaticScheduleDefinition
{
    private static ?ScheduleId $id = null;

    public static function build(): Schedule
    {
        self::$id ??= ScheduleId::generate();

        return new Schedule(
            id:       self::$id,
            name:     'int-colliding-name', // same name as X
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\IntDupNameY'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

final class IntSharedId
{
    private static ?ScheduleId $id = null;

    public static function get(): ScheduleId
    {
        return self::$id ??= ScheduleId::generate();
    }
}

#[Scheduled]
final class IntDuplicateIdX implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       IntSharedId::get(),
            name:     'int-dup-id-x',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\IntDupIdX'),
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
final class IntDuplicateIdY implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       IntSharedId::get(), // same ID as X
            name:     'int-dup-id-y',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\IntDupIdY'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}

// Implements interface but missing #[Scheduled] — will cause compile-time failure.
final class IntNoAttributeStatic implements StaticScheduleDefinition
{
    public static function build(): Schedule
    {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     'int-no-attribute',
            source:   ScheduleSource::Static,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\IntNoAttr'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
    }
}
