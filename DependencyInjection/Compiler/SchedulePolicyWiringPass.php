<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Scheduler\Security\SchedulerPermissionCatalog;
use Vortos\Scheduler\Security\SchedulePolicy;
use Vortos\Scheduler\Security\SchedulePolicyInterface;
use Vortos\Scheduler\Security\SchedulerResourcePolicy;

/**
 * Chooses the scheduler's RBAC policy after every extension has loaded.
 *
 * SchedulerExtension::load() decided this with
 *
 *     class_exists($policyEngineClass) && $container->hasDefinition($policyEngineClass)
 *
 * where the class is vortos-authorization's PolicyEngine. class_exists() answers "is
 * vortos-authorization installed?" and is order-free; hasDefinition() answers "has its extension
 * registered the engine YET?" and during load() is a race. Losing it silently aliased
 * SchedulePolicyInterface to NullSchedulePolicy — the scheduler's authorisation checks degrade to
 * permitting everything, on a deployment that has authorization installed and configured.
 *
 * A security control that fails OPEN because of extension ordering is the worst version of this
 * defect: nothing errors, nothing logs, and every schedule operation is simply unauthorised-but-
 * allowed. Resolving it in a compiler pass makes the question answerable.
 *
 * The dynamic class name is also why the architecture ratchet never caught this: it matches literal
 * `X::class` references, and a string in a variable is invisible to it.
 */
final class SchedulePolicyWiringPass implements CompilerPassInterface
{
    /** vortos-authorization's engine. A string because that package is an optional dependency. */
    private const POLICY_ENGINE = 'Vortos\Authorization\Engine\PolicyEngine';

    public function process(ContainerBuilder $container): void
    {
        // The extension has already registered NullSchedulePolicy as the alias target. This does
        // not bail on that: the whole point is to REPLACE the fail-open fallback once the real
        // engine is known to exist, which is only knowable here.
        if (!class_exists(self::POLICY_ENGINE) || !$container->has(self::POLICY_ENGINE)) {
            return; // vortos-authorization genuinely absent; the fallback is the right answer.
        }

        if ($container->hasDefinition(SchedulePolicy::class)) {
            return; // already upgraded
        }

        {
            $container->register(SchedulerResourcePolicy::class, SchedulerResourcePolicy::class)
                ->addTag('vortos.policy', ['resource' => 'scheduler'])
                ->setPublic(false);

            $container->register(SchedulerPermissionCatalog::class, SchedulerPermissionCatalog::class)
                ->addTag('vortos.permission_catalog', ['resource' => 'scheduler'])
                ->setPublic(false);

            $container->register(SchedulePolicy::class, SchedulePolicy::class)
                ->setArgument('$policyEngine', new Reference(self::POLICY_ENGINE))
                ->setPublic(false);

            $container->setAlias(SchedulePolicyInterface::class, SchedulePolicy::class);
        }
    }
}
