<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security;

use Vortos\Authorization\Attribute\AsPolicy;
use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Contract\PolicyInterface;
use Vortos\Authorization\Decision\PolicyDecision;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * Ownership policy for the "scheduler" resource.
 *
 * Tagged vortos.policy (resource: scheduler) so the PolicyEngine dispatches
 * scheduler.*.own permission checks here. The policy's sole responsibility is
 * confirming that the user's tenantId matches the schedule's tenantId for .own
 * scope. RBAC role checks are the engine's job — not this class's.
 */
#[AsPolicy(resource: 'scheduler')]
final class SchedulerResourcePolicy implements PolicyInterface
{
    public function can(
        AuthorizationContext $auth,
        string $action,
        string $scope,
        mixed $resource = null,
    ): bool|PolicyDecision {
        if ($scope === 'own') {
            if (!$resource instanceof Schedule) {
                return PolicyDecision::deny('resource_missing');
            }

            $tenantId = $auth->user()->getAttribute('tenant_id');

            if ($tenantId === null || $resource->tenantId !== $tenantId) {
                return PolicyDecision::deny('not_owner');
            }
        }

        return PolicyDecision::allow();
    }
}
