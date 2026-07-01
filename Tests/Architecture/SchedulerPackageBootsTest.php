<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\DependencyInjection\SchedulerExtension;
use Vortos\Scheduler\DependencyInjection\SchedulerPackage;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;

/**
 * Smoke test: the DI extension loads without throwing, even with an empty config array.
 * Verifies S0 is complete and the package can boot.
 */
final class SchedulerPackageBootsTest extends TestCase
{
    public function test_package_provides_extension(): void
    {
        $pkg = new SchedulerPackage();

        self::assertNotNull($pkg->getContainerExtension());
    }

    public function test_extension_alias_is_vortos_scheduler(): void
    {
        self::assertSame(
            'vortos_scheduler',
            (new SchedulerExtension())->getAlias(),
        );
    }

    public function test_extension_loads_without_error(): void
    {
        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        $this->addToAssertionCount(1);
    }

    public function test_clock_port_alias_is_registered(): void
    {
        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        self::assertTrue(
            $container->hasAlias(ClockPort::class),
            'ClockPort must be aliased to SystemClock after the extension loads.',
        );
    }

    public function test_psr20_clock_interface_alias_is_registered(): void
    {
        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        self::assertTrue(
            $container->hasAlias(\Psr\Clock\ClockInterface::class),
            'PSR-20 ClockInterface must be aliased to SystemClock for framework interop.',
        );
    }

    public function test_package_build_does_not_throw(): void
    {
        $pkg       = new SchedulerPackage();
        $container = new ContainerBuilder();
        $pkg->build($container);

        $this->addToAssertionCount(1);
    }

    public function test_in_memory_lease_store_is_registered_by_extension(): void
    {
        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        self::assertTrue(
            $container->hasDefinition(InMemoryLeaseStore::class),
            'InMemoryLeaseStore must always be registered — it has no external dependencies.',
        );
    }

    public function test_lease_port_alias_not_set_before_compiler_pass_runs(): void
    {
        $container = new ContainerBuilder();
        (new SchedulerExtension())->load([], $container);

        self::assertFalse(
            $container->hasAlias(\Vortos\Scheduler\Lease\LeasePort::class),
            'LeasePort alias must not be set by the extension alone — only the LeaseDriverPass sets it.',
        );
    }
}
