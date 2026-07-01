<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Security\SchedulePolicyInterface;

/**
 * Stub RBAC policy for unit tests.
 * Allows all operations by default; call deny() to make all checks throw.
 */
final class FakeSchedulePolicy implements SchedulePolicyInterface
{
    private bool $deny = false;

    public function deny(): void
    {
        $this->deny = true;
    }

    private function check(string $op, UserIdentityInterface $identity, Schedule $schedule): void
    {
        if ($this->deny) {
            throw new ScheduleAccessDeniedException($op, $identity->id());
        }
    }

    public function assertCanCreate(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->check('create', $identity, $schedule);
    }

    public function assertCanUpdate(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->check('update', $identity, $schedule);
    }

    public function assertCanPause(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->check('pause', $identity, $schedule);
    }

    public function assertCanDelete(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->check('delete', $identity, $schedule);
    }

    public function assertCanRunNow(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->check('run-now', $identity, $schedule);
    }

    public function canCreate(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return !$this->deny;
    }

    public function canUpdate(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return !$this->deny;
    }

    public function canPause(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return !$this->deny;
    }

    public function canDelete(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return !$this->deny;
    }

    public function canRunNow(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return !$this->deny;
    }

    public function assertCanManageRetention(UserIdentityInterface $identity, ?string $tenantId): void
    {
        if ($this->deny) {
            throw new ScheduleAccessDeniedException('retention.manage', $identity->id());
        }
    }

    public function canManageRetention(UserIdentityInterface $identity, ?string $tenantId): bool
    {
        return !$this->deny;
    }
}
