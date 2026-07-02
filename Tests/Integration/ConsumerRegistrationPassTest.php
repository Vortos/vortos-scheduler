<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Scheduler\Command\Handler\PruneSchedulerRunsHandler;
use Vortos\Scheduler\Console\SchedulerConsumeCommand;
use Vortos\Scheduler\DependencyInjection\Compiler\ConsumerRegistrationPass;
use Vortos\Scheduler\DependencyInjection\SchedulerExtension;
use Vortos\Scheduler\Doctor\SchedulerDoctor;
use Vortos\Scheduler\Engine\Consumer\FireQueueConsumer;

/**
 * Regression test for a cross-package DI-isolation bug: SchedulerExtension::load() gated the
 * whole fire-queue consumer (+ scheduler:consume command, worker, and the auto-prune command
 * handler) on a load()-time `$container->hasAlias(CommandBusInterface::class)`.
 *
 * The CommandBus alias is owned by vortos-cqrs. Under MergeExtensionConfigurationPass each
 * extension's load() runs against an isolated container, so that alias is NOT visible during
 * SchedulerExtension::load() even when the app installs the bus. The gate therefore read false
 * and silently skipped the consumer: scheduler:consume did not exist, the scheduler-consumer
 * worker FATAL'd, and scheduled commands were recorded "Dispatched" but never drained.
 *
 * Fix: the CommandBus-gated wiring moved to {@see ConsumerRegistrationPass}, which runs at build
 * time where the alias is visible. These tests assert the wiring is decided at pass time, not
 * load() time.
 */
final class ConsumerRegistrationPassTest extends TestCase
{
    private const COMMAND_BUS = 'Vortos\Cqrs\Command\CommandBusInterface';

    protected function tearDown(): void
    {
        // phpunit.xml pins this to 0 suite-wide; restore it so a per-case override never leaks.
        $_ENV['SCHEDULER_RUN_RETENTION_DAYS'] = '0';
    }

    public function test_load_does_not_register_consumer_because_bus_is_invisible_under_isolation(): void
    {
        // The alias is absent while load() runs (mirrors MergeExtensionConfigurationPass). The
        // consumer must NOT be wired here — otherwise the gate would again depend on a value
        // load() cannot see.
        $container = $this->loadSchedulerWithoutBus();

        self::assertFalse($container->hasDefinition(FireQueueConsumer::class));
        self::assertFalse(
            $container->getDefinition(SchedulerDoctor::class)->getArgument('$fireQueueConsumerInstalled'),
        );
    }

    public function test_pass_registers_consumer_when_bus_becomes_visible_at_build_time(): void
    {
        $container = $this->loadSchedulerWithoutBus();

        // The alias only becomes visible after all extensions merge — i.e. at compile time, which
        // is exactly when the pass runs.
        $this->registerFakeCommandBus($container);
        (new ConsumerRegistrationPass())->process($container);

        self::assertTrue($container->hasDefinition(FireQueueConsumer::class));
        self::assertTrue($container->hasDefinition(SchedulerConsumeCommand::class));
        self::assertTrue(
            $container->getDefinition(SchedulerConsumeCommand::class)->hasTag('console.command'),
            'scheduler:consume must carry the console.command tag or the worker FATALs.',
        );
        self::assertTrue(
            $container->getDefinition(SchedulerDoctor::class)->getArgument('$fireQueueConsumerInstalled'),
            'SchedulerDoctor C11 must report the consumer as installed once wired.',
        );
    }

    public function test_pass_registers_auto_prune_handler_when_active_and_bus_visible(): void
    {
        // Auto-prune is only active when retention is enabled (phpunit pins it to 0 suite-wide).
        $_ENV['SCHEDULER_RUN_RETENTION_DAYS'] = '30';
        $container = $this->loadSchedulerWithoutBus();
        $this->registerFakeCommandBus($container);
        (new ConsumerRegistrationPass())->process($container);

        self::assertTrue($container->hasDefinition(PruneSchedulerRunsHandler::class));
        self::assertTrue(
            $container->getDefinition(PruneSchedulerRunsHandler::class)->hasTag('vortos.command_handler'),
        );
    }

    public function test_pass_skips_auto_prune_handler_when_retention_disabled(): void
    {
        // Retention disabled (the suite default) ⇒ no auto-prune schedule ⇒ no handler, even
        // though the CommandBus is present and the consumer itself is wired.
        $container = $this->loadSchedulerWithoutBus();
        $this->registerFakeCommandBus($container);
        (new ConsumerRegistrationPass())->process($container);

        self::assertTrue($container->hasDefinition(FireQueueConsumer::class));
        self::assertFalse($container->hasDefinition(PruneSchedulerRunsHandler::class));
    }

    public function test_pass_skips_everything_when_no_command_bus(): void
    {
        $container = $this->loadSchedulerWithoutBus();
        (new ConsumerRegistrationPass())->process($container);

        self::assertFalse($container->hasDefinition(FireQueueConsumer::class));
        self::assertFalse($container->hasDefinition(SchedulerConsumeCommand::class));
        self::assertFalse($container->hasDefinition(PruneSchedulerRunsHandler::class));
        self::assertFalse(
            $container->getDefinition(SchedulerDoctor::class)->getArgument('$fireQueueConsumerInstalled'),
        );
    }

    private function loadSchedulerWithoutBus(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Same hard requirements as SchedulerExtension::load() (see MinimalContainerDispatchChainTest).
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/missing_vortos_scheduler_config');
        $container->setParameter('kernel.env', 'test');

        $container->register(Connection::class, Connection::class)
            ->setPublic(true)
            ->setSynthetic(true);
        $container->register(LoggerInterface::class, NullLogger::class)->setPublic(false);

        (new SchedulerExtension())->load([], $container);

        return $container;
    }

    private function registerFakeCommandBus(ContainerBuilder $container): void
    {
        $container->register('test.command_bus')
            ->setSynthetic(true)
            ->setPublic(true);
        $container->setAlias(self::COMMAND_BUS, 'test.command_bus')->setPublic(true);
    }
}
