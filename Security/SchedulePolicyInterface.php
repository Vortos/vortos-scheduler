<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;

/**
 * RBAC gate for all mutable scheduler operations.
 *
 * The concrete implementation (SchedulePolicy) wraps the Vortos PolicyEngine.
 * NullSchedulePolicy is used when vortos-authorization is not installed —
 * it logs a WARNING and allows all operations.
 *
 * Permission strings registered with the PolicyEngine:
 *   scheduler.create.own / scheduler.create.any
 *   scheduler.update.own / scheduler.update.any
 *   scheduler.pause.own  / scheduler.pause.any
 *   scheduler.delete.own / scheduler.delete.any
 *   scheduler.run-now.own / scheduler.run-now.any
 *
 * Soft-checks (canXxx) return bool and never throw — used by the Admin UI to
 * conditionally render action buttons before the user attempts an operation.
 */
interface SchedulePolicyInterface
{
    /** @throws ScheduleAccessDeniedException */
    public function assertCanCreate(UserIdentityInterface $identity, Schedule $schedule): void;

    /** @throws ScheduleAccessDeniedException */
    public function assertCanUpdate(UserIdentityInterface $identity, Schedule $schedule): void;

    /** @throws ScheduleAccessDeniedException */
    public function assertCanPause(UserIdentityInterface $identity, Schedule $schedule): void;

    /** @throws ScheduleAccessDeniedException */
    public function assertCanDelete(UserIdentityInterface $identity, Schedule $schedule): void;

    /** @throws ScheduleAccessDeniedException */
    public function assertCanRunNow(UserIdentityInterface $identity, Schedule $schedule): void;

    public function canCreate(UserIdentityInterface $identity, Schedule $schedule): bool;

    public function canUpdate(UserIdentityInterface $identity, Schedule $schedule): bool;

    public function canPause(UserIdentityInterface $identity, Schedule $schedule): bool;

    public function canDelete(UserIdentityInterface $identity, Schedule $schedule): bool;

    public function canRunNow(UserIdentityInterface $identity, Schedule $schedule): bool;
}
