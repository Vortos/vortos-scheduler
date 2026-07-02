<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Scheduler\Command\Handler\PruneSchedulerRunsHandler;
use Vortos\Scheduler\Console\SchedulerConsumeCommand;
use Vortos\Scheduler\Doctor\SchedulerDoctor;
use Vortos\Scheduler\Engine\Consumer\FireQueueConsumer;
use Vortos\Scheduler\Fire\CommandHydrator;
use Vortos\Scheduler\Observability\SchedulerMetricsPort;
use Vortos\Scheduler\Observability\SchedulerTracer;
use Vortos\Scheduler\Retention\RunRetentionSweeper;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;

/**
 * Registers everything that depends on the CQRS CommandBus — the fire-queue consumer chain
 * (S12) and the auto-prune command handler — at container-BUILD time rather than in
 * SchedulerExtension::load().
 *
 * ## Why a compiler pass and not load()
 *
 * The CommandBusInterface alias is owned by another package (vortos-cqrs). Under Symfony's
 * MergeExtensionConfigurationPass, each extension's load() runs against an ISOLATED container,
 * so a load()-time `$container->hasAlias(CommandBusInterface::class)` returns false even when
 * the app really does install the bus — the alias only becomes visible after all extensions are
 * merged. Doing the gate in load() therefore silently skipped the consumer: `scheduler:consume`
 * was never registered, the scheduler-consumer worker FATAL'd (command not defined), and
 * scheduled commands were recorded as "Dispatched" but never drained from the fire queue.
 *
 * Compiler passes run against the fully-merged container, where cross-package aliases ARE
 * visible — so the CommandBus gate is finally reliable here. Same remedy applied framework-wide
 * for the load()-time cross-package `has()` anti-pattern.
 *
 * ## Ordering
 *
 * Priority 60 in TYPE_BEFORE_OPTIMIZATION, which must beat:
 *   - Cqrs CommandHandlerPass (priority 50) — so the auto-prune `vortos.command_handler` is
 *     collected into the command→handler map.
 *   - Foundation ConsoleCommandPass (priority 0) — so `scheduler:consume` is wired into the
 *     console Application before command collection.
 * Its own dependencies (the CommandBus alias, the DBAL stores, the tracer, the sweeper) all
 * come from load(), so nothing higher-priority is required.
 */
final class ConsumerRegistrationPass implements CompilerPassInterface
{
    private const COMMAND_BUS_INTERFACE = 'Vortos\Cqrs\Command\CommandBusInterface';

    public function process(ContainerBuilder $container): void
    {
        if (!class_exists(Connection::class) || !$this->hasCommandBus($container)) {
            return;
        }

        $this->registerConsumer($container);
        $this->registerAutoPruneHandler($container);
    }

    private function hasCommandBus(ContainerBuilder $container): bool
    {
        return interface_exists(self::COMMAND_BUS_INTERFACE)
            && ($container->hasAlias(self::COMMAND_BUS_INTERFACE) || $container->hasDefinition(self::COMMAND_BUS_INTERFACE));
    }

    private function registerConsumer(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(FireQueueConsumer::class)) {
            return;
        }

        $prefix = $this->tablePrefix($container);

        $container->register(CommandHydrator::class, CommandHydrator::class)
            ->setPublic(false);

        $container->register(FireQueueConsumer::class, FireQueueConsumer::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$runStore', new Reference(ScheduleRunStoreInterface::class))
            ->setArgument('$commandBus', new Reference(self::COMMAND_BUS_INTERFACE))
            ->setArgument('$hydrator', new Reference(CommandHydrator::class))
            ->setArgument('$clock', new Reference(ClockInterface::class))
            ->setArgument('$tracer', new Reference(SchedulerTracer::class))
            ->setArgument('$metrics', new Reference(SchedulerMetricsPort::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$logger', new Reference(LoggerInterface::class))
            ->setArgument('$table', $prefix . 'scheduler_fire_queue')
            ->setPublic(false);

        $container->register(SchedulerConsumeCommand::class, SchedulerConsumeCommand::class)
            ->setArgument('$consumer', new Reference(FireQueueConsumer::class))
            ->setArgument('$defaultBatchSize', $this->intParam($container, 'vortos_scheduler.consume_batch_size', 100))
            ->setArgument('$defaultPollIntervalSec', $this->intParam($container, 'vortos_scheduler.consume_poll_interval_sec', 5))
            ->addTag('console.command')
            ->setPublic(false);

        if (\class_exists(\Vortos\Docker\Worker\WorkerProcessDefinition::class)) {
            $container->register('vortos_scheduler.worker.consumer', \Vortos\Docker\Worker\WorkerProcessDefinition::class)
                ->setArguments([
                    'scheduler-consumer',
                    'php /var/www/html/bin/console scheduler:consume --loop',
                    'Vortos Scheduler: fire-queue consumer (drains scheduled commands into the CQRS bus).',
                    true,  // autostart
                    true,  // autorestart
                ])
                ->addTag('vortos.worker')
                ->setPublic(false);
        }

        // Let SchedulerDoctor's C11 report the consumer as actually installed. The default set in
        // registerDoctor() is false because that runs in load(), before this pass decides.
        if ($container->hasDefinition(SchedulerDoctor::class)) {
            $container->getDefinition(SchedulerDoctor::class)
                ->replaceArgument('$fireQueueConsumerInstalled', true);
        }
    }

    private function registerAutoPruneHandler(ContainerBuilder $container): void
    {
        // Only when the auto-prune schedule is actually active (retention enabled). The extension
        // sets this flag in registerRetention(); a config decision, unaffected by DI isolation.
        if (!$container->hasParameter('vortos_scheduler.auto_prune_active')
            || $container->getParameter('vortos_scheduler.auto_prune_active') !== true) {
            return;
        }

        if ($container->hasDefinition(PruneSchedulerRunsHandler::class)) {
            return;
        }

        $container->register(PruneSchedulerRunsHandler::class, PruneSchedulerRunsHandler::class)
            ->setArgument('$sweeper', new Reference(RunRetentionSweeper::class))
            ->addTag('vortos.command_handler')
            ->setPublic(true);
    }

    private function tablePrefix(ContainerBuilder $container): string
    {
        return $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';
    }

    private function intParam(ContainerBuilder $container, string $name, int $default): int
    {
        return $container->hasParameter($name) ? (int) $container->getParameter($name) : $default;
    }
}
