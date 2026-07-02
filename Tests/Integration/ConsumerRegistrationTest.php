<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Scheduler\Command\Handler\PruneSchedulerRunsHandler;
use Vortos\Scheduler\Console\SchedulerConsumeCommand;
use Vortos\Scheduler\DependencyInjection\SchedulerExtension;
use Vortos\Scheduler\Engine\Consumer\FireQueueConsumer;

/**
 * Regression test for the fire-queue consumer's cross-package DI-isolation bug.
 *
 * The original `SchedulerExtension::registerConsumer()` gated the whole consumer on a load()-time
 * `$container->hasAlias(CommandBusInterface::class)`. Under MergeExtensionConfigurationPass that
 * cross-package alias is invisible during load(), so the check read false even when the bus was
 * installed and the consumer (plus scheduler:consume and the worker) was silently skipped.
 *
 * The fix registers the consumer unconditionally whenever DBAL + vortos-cqrs are installed (both
 * pure autoload checks, reliable in load()) and injects the bus with NULL_ON_INVALID_REFERENCE, so
 * scheduler:consume ALWAYS exists and a missing/unwired bus surfaces as a loud runtime error
 * (FireQueueConsumer::consumeBatch) rather than a vanished subsystem. These tests assert the
 * consumer is wired without ever registering the CommandBus alias.
 */
final class ConsumerRegistrationTest extends TestCase
{
    protected function tearDown(): void
    {
        // phpunit.xml pins retention to 0 suite-wide; restore it so a per-case override never leaks.
        $_ENV['SCHEDULER_RUN_RETENTION_DAYS'] = '0';
    }

    public function test_consumer_is_registered_even_without_the_command_bus_alias(): void
    {
        $container = $this->loadScheduler();

        self::assertTrue(
            $container->hasDefinition(FireQueueConsumer::class),
            'The consumer must be registered regardless of whether the CommandBus alias is visible '
            . 'during load() — the old hasAlias() gate was the isolation bug.',
        );
        self::assertTrue($container->hasDefinition(SchedulerConsumeCommand::class));
        self::assertTrue(
            $container->getDefinition(SchedulerConsumeCommand::class)->hasTag('console.command'),
            'scheduler:consume must carry the console.command tag or its worker FATALs.',
        );
    }

    public function test_command_bus_is_injected_as_an_optional_reference(): void
    {
        $container = $this->loadScheduler();

        $busArg = $container->getDefinition(FireQueueConsumer::class)->getArgument('$commandBus');

        self::assertInstanceOf(Reference::class, $busArg);
        self::assertSame(
            ContainerInterface::NULL_ON_INVALID_REFERENCE,
            $busArg->getInvalidBehavior(),
            'A null-tolerant reference is what lets the consumer register without a wired bus and '
            . 'fail loudly at run time instead of vanishing at compile time.',
        );
    }

    public function test_auto_prune_handler_registered_when_retention_active(): void
    {
        $_ENV['SCHEDULER_RUN_RETENTION_DAYS'] = '30';
        $container = $this->loadScheduler();

        self::assertTrue($container->hasDefinition(PruneSchedulerRunsHandler::class));
        self::assertTrue(
            $container->getDefinition(PruneSchedulerRunsHandler::class)->hasTag('vortos.command_handler'),
        );
    }

    public function test_auto_prune_handler_absent_when_retention_disabled(): void
    {
        // Suite default (retention 0) ⇒ no auto-prune schedule ⇒ no handler, though the consumer
        // itself is still wired.
        $container = $this->loadScheduler();

        self::assertTrue($container->hasDefinition(FireQueueConsumer::class));
        self::assertFalse($container->hasDefinition(PruneSchedulerRunsHandler::class));
    }

    private function loadScheduler(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Same hard requirements as SchedulerExtension::load() (see MinimalContainerDispatchChainTest).
        // Note: the CommandBus alias is deliberately NOT registered — the consumer must wire anyway.
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/missing_vortos_scheduler_config');
        $container->setParameter('kernel.env', 'test');

        $container->register(Connection::class, Connection::class)
            ->setPublic(true)
            ->setSynthetic(true);
        $container->register(LoggerInterface::class, NullLogger::class)->setPublic(false);

        (new SchedulerExtension())->load([], $container);

        return $container;
    }
}
