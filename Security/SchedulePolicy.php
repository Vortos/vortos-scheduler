<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;

/**
 * RBAC-backed schedule policy.
 *
 * Checks own-scope first (narrower), then any-scope. The SchedulerResourcePolicy
 * registered with the PolicyEngine enforces tenantId ownership for .own permissions.
 * If neither scope is granted, throws ScheduleAccessDeniedException.
 */
final class SchedulePolicy implements SchedulePolicyInterface
{
    public function __construct(private readonly PolicyEngine $policyEngine) {}

    public function assertCanCreate(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->assertPermission($identity, 'scheduler.create', $schedule);
    }

    public function assertCanUpdate(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->assertPermission($identity, 'scheduler.update', $schedule);
    }

    public function assertCanPause(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->assertPermission($identity, 'scheduler.pause', $schedule);
    }

    public function assertCanDelete(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->assertPermission($identity, 'scheduler.delete', $schedule);
    }

    public function assertCanRunNow(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->assertPermission($identity, 'scheduler.run-now', $schedule);
    }

    public function assertCanManageRetention(UserIdentityInterface $identity, ?string $tenantId): void
    {
        if ($this->policyEngine->can($identity, 'scheduler.retention.manage')) {
            return;
        }

        throw new ScheduleAccessDeniedException(
            action: 'retention.manage',
            identityId: $identity->id(),
        );
    }

    public function canManageRetention(UserIdentityInterface $identity, ?string $tenantId): bool
    {
        return $this->policyEngine->can($identity, 'scheduler.retention.manage');
    }

    public function canCreate(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return $this->checkPermission($identity, 'scheduler.create', $schedule);
    }

    public function canUpdate(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return $this->checkPermission($identity, 'scheduler.update', $schedule);
    }

    public function canPause(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return $this->checkPermission($identity, 'scheduler.pause', $schedule);
    }

    public function canDelete(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return $this->checkPermission($identity, 'scheduler.delete', $schedule);
    }

    public function canRunNow(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return $this->checkPermission($identity, 'scheduler.run-now', $schedule);
    }

    private function assertPermission(
        UserIdentityInterface $identity,
        string $basePermission,
        Schedule $schedule,
    ): void {
        if ($this->policyEngine->can($identity, $basePermission . '.own', $schedule)) {
            return;
        }

        if ($this->policyEngine->can($identity, $basePermission . '.any', $schedule)) {
            return;
        }

        throw new ScheduleAccessDeniedException(
            action: $basePermission,
            identityId: $identity->id(),
        );
    }

    private function checkPermission(
        UserIdentityInterface $identity,
        string $basePermission,
        Schedule $schedule,
    ): bool {
        return $this->policyEngine->can($identity, $basePermission . '.own', $schedule)
            || $this->policyEngine->can($identity, $basePermission . '.any', $schedule);
    }
}
