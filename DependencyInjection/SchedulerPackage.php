<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\Scheduler\DependencyInjection\Compiler\LeaseDriverPass;
use Vortos\Scheduler\DependencyInjection\Compiler\SchedulableCommandPass;
use Vortos\Scheduler\DependencyInjection\Compiler\StaticSchedulePass;

final class SchedulerPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SchedulerExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // StaticSchedulePass runs first: discovers and validates static schedule definitions.
        // LeaseDriverPass runs after: validates the lease driver is reachable.
        $container->addCompilerPass(
            new StaticSchedulePass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -33,
        );
        $container->addCompilerPass(
            new LeaseDriverPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -32,
        );
        // SchedulableCommandPass runs after StaticSchedulePass so it can cross-check
        // static schedule commands against the allowlist at container build time.
        $container->addCompilerPass(
            new SchedulableCommandPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            -30,
        );
    }
}
