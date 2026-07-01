<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Domain\Command\CommandInterface;
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Security\Attribute\SchedulableCommand;
use Vortos\Scheduler\Security\CommandSpecValidator;

/**
 * Builds the compile-time command allowlist from all classes carrying #[SchedulableCommand].
 *
 * Runs at priority -30, after StaticSchedulePass (-33) has filled StaticScheduleRegistry
 * with static schedule class names. This pass:
 *   1. Collects all service classes tagged "vortos.schedulable_command" or carrying
 *      the #[SchedulableCommand] PHP attribute.
 *   2. If the allowlist is non-empty, cross-checks every static schedule's commandClass
 *      against it — container build fails if a static schedule references a command
 *      that is not allowlisted.
 *   3. Registers CommandSpecValidator and injects it into FireDispatcher as step-0
 *      defence-in-depth guard.
 *
 * When no commands are allowlisted, the validator is NOT registered and FireDispatcher
 * runs without it — backward-compatible for projects that haven't opted in yet.
 */
final class SchedulableCommandPass implements CompilerPassInterface
{
    public const TAG = 'vortos.schedulable_command';

    public function process(ContainerBuilder $container): void
    {
        $allowlist = $this->buildAllowlist($container);

        if (empty($allowlist)) {
            return; // No allowlisted commands — validator not activated
        }

        $this->assertAllowlistedCommandsAreDispatchable($allowlist);
        $this->crossCheckStaticSchedules($container, $allowlist);
        $this->registerValidator($container, $allowlist);
    }

    /**
     * S12: the fire-queue consumer dispatches allowlisted commands through the CQRS
     * CommandBus, which requires CommandInterface. Catching a mismatch here (deploy
     * time) is strictly better than discovering it when a fire-queue row throws at
     * hydration time in production.
     *
     * @param array<string, true> $allowlist
     */
    private function assertAllowlistedCommandsAreDispatchable(array $allowlist): void
    {
        if (!interface_exists(CommandInterface::class)) {
            return; // vortos-domain not installed in this container's dependency set
        }

        foreach (array_keys($allowlist) as $class) {
            if (!is_a($class, CommandInterface::class, true)) {
                throw new \RuntimeException(sprintf(
                    'Command "%s" carries #[SchedulableCommand] but does not implement %s. '
                    . 'The scheduler dispatches allowlisted commands through the CQRS CommandBus, '
                    . 'which requires every command to implement CommandInterface (idempotencyKey()).',
                    $class,
                    CommandInterface::class,
                ));
            }
        }
    }

    /**
     * @return array<string, true>
     */
    private function buildAllowlist(ContainerBuilder $container): array
    {
        $allowlist = [];

        // Collect via explicit tag
        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $serviceId) {
            $def   = $container->getDefinition($serviceId);
            $class = $def->getClass();
            if ($class !== null) {
                $allowlist[$class] = true;
            }
        }

        // Collect via #[SchedulableCommand] PHP attribute on any registered service
        foreach ($container->getDefinitions() as $definition) {
            $class = $definition->getClass();
            if ($class === null || !class_exists($class)) {
                continue;
            }

            $attrs = (new \ReflectionClass($class))->getAttributes(SchedulableCommand::class);
            if (!empty($attrs)) {
                $allowlist[$class] = true;
            }
        }

        return $allowlist;
    }

    /**
     * @param array<string, true> $allowlist
     */
    private function crossCheckStaticSchedules(ContainerBuilder $container, array $allowlist): void
    {
        if (!$container->hasDefinition(StaticScheduleRegistry::class)) {
            return;
        }

        /** @var list<class-string> $definitionClasses */
        $definitionClasses = $container->getDefinition(StaticScheduleRegistry::class)
            ->getArgument('$definitionClasses');

        foreach ($definitionClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $schedule     = $class::build();
            $commandClass = $schedule->command->commandClass;

            if (!isset($allowlist[$commandClass])) {
                throw new \RuntimeException(sprintf(
                    'Static schedule "%s" (class %s) uses command "%s" which is not allowlisted. '
                    . 'Add #[SchedulableCommand] to "%s" to permit it in the scheduler.',
                    $schedule->name,
                    $class,
                    $commandClass,
                    $commandClass,
                ));
            }
        }
    }

    /**
     * @param array<string, true> $allowlist
     */
    private function registerValidator(ContainerBuilder $container, array $allowlist): void
    {
        $container->register(CommandSpecValidator::class, CommandSpecValidator::class)
            ->setArgument('$allowlist', $allowlist)
            ->setPublic(false);

        if ($container->hasDefinition(FireDispatcher::class)) {
            $container->getDefinition(FireDispatcher::class)
                ->setArgument('$validator', new Reference(CommandSpecValidator::class));
        }
    }
}
