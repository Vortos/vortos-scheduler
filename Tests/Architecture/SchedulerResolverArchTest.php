<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Scheduler\DependencyInjection\Compiler\StaticSchedulePass;
use Vortos\Scheduler\DependencyInjection\SchedulerExtension;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Attribute\Scheduled;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * Architecture / structural tests for the S6 Registry layer.
 *
 * Verifies class-level invariants, method signatures, DI wiring,
 * and that the Registry/ namespace remains pure (no I/O imports).
 */
final class SchedulerResolverArchTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // Class-level invariants
    // ─────────────────────────────────────────────────────────────

    public function test_static_schedule_registry_is_final(): void
    {
        self::assertTrue((new \ReflectionClass(StaticScheduleRegistry::class))->isFinal());
    }

    public function test_schedule_resolver_is_final(): void
    {
        self::assertTrue((new \ReflectionClass(ScheduleResolver::class))->isFinal());
    }

    public function test_static_schedule_definition_is_interface(): void
    {
        self::assertTrue((new \ReflectionClass(StaticScheduleDefinition::class))->isInterface());
    }

    public function test_scheduled_is_attribute_targeting_class(): void
    {
        $attrs = (new \ReflectionClass(Scheduled::class))
            ->getAttributes(\Attribute::class);

        self::assertNotEmpty($attrs, 'Scheduled must carry #[\Attribute]');

        /** @var \Attribute $attr */
        $attr = $attrs[0]->newInstance();
        self::assertSame(\Attribute::TARGET_CLASS, $attr->flags & \Attribute::TARGET_CLASS);
    }

    public function test_static_schedule_pass_implements_compiler_pass_interface(): void
    {
        self::assertTrue(
            (new \ReflectionClass(StaticSchedulePass::class))
                ->implementsInterface(CompilerPassInterface::class),
        );
    }

    public function test_static_schedule_pass_is_final(): void
    {
        self::assertTrue((new \ReflectionClass(StaticSchedulePass::class))->isFinal());
    }

    // ─────────────────────────────────────────────────────────────
    // TAG constant
    // ─────────────────────────────────────────────────────────────

    public function test_static_schedule_pass_tag_is_vortos_scheduler_static_schedule(): void
    {
        self::assertSame('vortos_scheduler.static_schedule', StaticSchedulePass::TAG);
    }

    // ─────────────────────────────────────────────────────────────
    // StaticScheduleDefinition::build() is static
    // ─────────────────────────────────────────────────────────────

    public function test_static_schedule_definition_build_is_static(): void
    {
        $method = (new \ReflectionClass(StaticScheduleDefinition::class))->getMethod('build');
        self::assertTrue($method->isStatic(), 'StaticScheduleDefinition::build() must be static');
    }

    public function test_static_schedule_definition_build_returns_schedule(): void
    {
        $method     = (new \ReflectionClass(StaticScheduleDefinition::class))->getMethod('build');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame(Schedule::class, (string) $returnType);
    }

    // ─────────────────────────────────────────────────────────────
    // ScheduleResolver method signatures
    // ─────────────────────────────────────────────────────────────

    public function test_schedule_resolver_has_active_view_method(): void
    {
        $r = new \ReflectionClass(ScheduleResolver::class);
        self::assertTrue($r->hasMethod('activeView'));
        self::assertTrue($r->getMethod('activeView')->isPublic());
    }

    public function test_schedule_resolver_active_view_return_type_is_iterable(): void
    {
        $method     = (new \ReflectionClass(ScheduleResolver::class))->getMethod('activeView');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame('iterable', (string) $returnType);
    }

    public function test_schedule_resolver_has_static_count_returning_int(): void
    {
        $method     = (new \ReflectionClass(ScheduleResolver::class))->getMethod('staticCount');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame('int', (string) $returnType);
    }

    public function test_schedule_resolver_has_has_static_schedules_returning_bool(): void
    {
        $method     = (new \ReflectionClass(ScheduleResolver::class))->getMethod('hasStaticSchedules');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame('bool', (string) $returnType);
    }

    public function test_schedule_resolver_get_registry_returns_static_schedule_registry(): void
    {
        $method     = (new \ReflectionClass(ScheduleResolver::class))->getMethod('getRegistry');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame(StaticScheduleRegistry::class, (string) $returnType);
    }

    // ─────────────────────────────────────────────────────────────
    // StaticScheduleRegistry method signatures
    // ─────────────────────────────────────────────────────────────

    public function test_static_schedule_registry_all_returns_array(): void
    {
        $method     = (new \ReflectionClass(StaticScheduleRegistry::class))->getMethod('all');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame('array', (string) $returnType);
    }

    public function test_static_schedule_registry_is_empty_returns_bool(): void
    {
        $method     = (new \ReflectionClass(StaticScheduleRegistry::class))->getMethod('isEmpty');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertSame('bool', (string) $returnType);
    }

    public function test_static_schedule_registry_find_by_id_returns_nullable_schedule(): void
    {
        $method     = (new \ReflectionClass(StaticScheduleRegistry::class))->getMethod('findById');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertTrue($returnType->allowsNull());
        self::assertSame(Schedule::class, $returnType->getName());
    }

    public function test_static_schedule_registry_find_by_name_returns_nullable_schedule(): void
    {
        $method     = (new \ReflectionClass(StaticScheduleRegistry::class))->getMethod('findByName');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType);
        self::assertInstanceOf(\ReflectionNamedType::class, $returnType);
        self::assertTrue($returnType->allowsNull());
        self::assertSame(Schedule::class, $returnType->getName());
    }

    // ─────────────────────────────────────────────────────────────
    // DI extension registers Registry and Resolver
    // ─────────────────────────────────────────────────────────────

    public function test_extension_registers_static_schedule_registry(): void
    {
        $container = $this->newContainer();
        (new SchedulerExtension())->load([], $container);

        self::assertTrue(
            $container->hasDefinition(StaticScheduleRegistry::class),
            'SchedulerExtension must register StaticScheduleRegistry',
        );
    }

    public function test_extension_registers_schedule_resolver_when_dbal_available(): void
    {
        if (!\class_exists(\Doctrine\DBAL\Connection::class)) {
            $this->markTestSkipped('DBAL not available.');
        }

        $container = $this->newContainer();
        (new SchedulerExtension())->load([], $container);

        self::assertTrue(
            $container->hasDefinition(ScheduleResolver::class),
            'SchedulerExtension must register ScheduleResolver when DBAL is available',
        );
    }

    public function test_extension_autoconfigures_static_schedule_definition_with_tag(): void
    {
        $container = $this->newContainer();
        (new SchedulerExtension())->load([], $container);

        $autoconfigured = $container->getAutoconfiguredInstanceof();

        self::assertArrayHasKey(
            StaticScheduleDefinition::class,
            $autoconfigured,
            'SchedulerExtension must autoconfigure StaticScheduleDefinition with the static_schedule tag',
        );

        $tags = $autoconfigured[StaticScheduleDefinition::class]->getTags();
        self::assertArrayHasKey(StaticSchedulePass::TAG, $tags);
    }

    // ─────────────────────────────────────────────────────────────
    // Registry/ namespace has no I/O imports (sampled check)
    // ─────────────────────────────────────────────────────────────

    public function test_registry_files_have_no_dbal_imports(): void
    {
        $registryDir = dirname(__DIR__, 2) . '/Registry';

        if (!is_dir($registryDir)) {
            $this->markTestSkipped('Registry/ directory does not exist.');
        }

        $violations = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($registryDir)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getRealPath());
            foreach (['Doctrine\\DBAL', 'Doctrine\\ORM', '\\Redis', 'Predis\\'] as $forbidden) {
                if (str_contains($content, $forbidden)) {
                    $violations[] = $file->getFilename() . ' imports ' . $forbidden;
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'Registry/ must be free of I/O imports: ' . implode(', ', $violations),
        );
    }

    /**
     * SchedulerExtension::load() hard-requires kernel.project_dir/kernel.env (same
     * convention as CacheExtension, AuthExtension, ... — see CacheExtensionEnvDefaultsTest).
     */
    private function newContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/missing_vortos_scheduler_config');
        $container->setParameter('kernel.env', 'test');

        return $container;
    }
}
