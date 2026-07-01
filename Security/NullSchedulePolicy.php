<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security;

use Psr\Log\LoggerInterface;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * No-op policy used when vortos-authorization is not installed.
 *
 * Logs a WARNING on every call so that operations are visible in the
 * application log without blocking them. Replace with SchedulePolicy
 * by installing vortos/vortos-authorization.
 */
final class NullSchedulePolicy implements SchedulePolicyInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function assertCanCreate(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->warn('create', $identity);
    }

    public function assertCanUpdate(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->warn('update', $identity);
    }

    public function assertCanPause(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->warn('pause', $identity);
    }

    public function assertCanDelete(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->warn('delete', $identity);
    }

    public function assertCanRunNow(UserIdentityInterface $identity, Schedule $schedule): void
    {
        $this->warn('run-now', $identity);
    }

    public function canCreate(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return true;
    }

    public function canUpdate(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return true;
    }

    public function canPause(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return true;
    }

    public function canDelete(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return true;
    }

    public function canRunNow(UserIdentityInterface $identity, Schedule $schedule): bool
    {
        return true;
    }

    private function warn(string $action, UserIdentityInterface $identity): void
    {
        $this->logger->warning(
            'Scheduler RBAC is disabled: vortos-authorization not installed. '
            . 'Action "{action}" by identity "{identity_id}" is allowed without authorization check.',
            ['action' => $action, 'identity_id' => $identity->id()],
        );
    }
}
