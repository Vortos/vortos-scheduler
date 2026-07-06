<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine\Consumer;

/**
 * Resolves the set of command classes THIS consumer node is capable of dispatching, so the
 * fire-queue claim only pulls fires the node can actually run.
 *
 * This is the structural fix for SCHED-1: during a blue/green (heterogeneous-image) rollout a
 * stale standby must not claim a fire for a command class its image doesn't contain.
 */
interface ConsumerCapabilityResolverInterface
{
    /**
     * @return list<string>|null The command classes this node can run, or null to apply NO
     *                           capability filter (claim all — for deployments that have not
     *                           opted into the #[SchedulableCommand] allowlist). An empty list
     *                           means "capable of nothing" and the node claims no fires.
     */
    public function capableCommandClasses(): ?array;
}
