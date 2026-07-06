<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine\Consumer;

use Vortos\Scheduler\Security\CommandSpecValidator;

/**
 * Derives this node's capability set from the compile-time #[SchedulableCommand] allowlist
 * (built by SchedulableCommandPass), intersected with the classes actually loadable in this
 * container.
 *
 * The intersection is the key: a stale blue/green image that predates a newly-added command
 * simply does not have that class, so it is excluded from the node's capabilities and the node's
 * claim query will leave that fire for a capable consumer.
 *
 * When no allowlist is configured (the validator is not registered — a project that has not opted
 * into #[SchedulableCommand]), this resolves to null = "no capability filter", preserving the
 * claim-all behaviour for those deployments. The consumer's requeue safety net still protects
 * them against an unknown class.
 */
final class AllowlistConsumerCapabilityResolver implements ConsumerCapabilityResolverInterface
{
    public function __construct(
        private readonly ?CommandSpecValidator $validator = null,
    ) {}

    public function capableCommandClasses(): ?array
    {
        if ($this->validator === null) {
            return null; // no allowlist → no capability filter
        }

        $capable = [];
        foreach ($this->validator->allowlistedClasses() as $class) {
            if (class_exists($class)) {
                $capable[] = $class;
            }
        }

        return $capable;
    }
}
