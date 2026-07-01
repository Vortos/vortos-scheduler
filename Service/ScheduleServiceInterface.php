<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Service;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalRequest;

interface ScheduleServiceInterface
{
    public function create(Schedule $schedule, UserIdentityInterface $actor): Schedule;

    public function update(Schedule $schedule, UserIdentityInterface $actor, ?string $reason = null): Schedule;

    public function delete(
        ScheduleId            $id,
        ?string               $tenantId,
        UserIdentityInterface $actor,
        ?string               $reason = null,
    ): void;

    public function loadSchedule(ScheduleId $id, ?string $tenantId): Schedule;

    public function pause(
        ScheduleId            $id,
        ?string               $tenantId,
        UserIdentityInterface $actor,
        ?string               $reason = null,
    ): Schedule;

    public function resume(
        ScheduleId            $id,
        ?string               $tenantId,
        UserIdentityInterface $actor,
    ): Schedule;

    public function runNow(
        ScheduleId            $id,
        ?string               $tenantId,
        UserIdentityInterface $actor,
        ?string               $reason = null,
    ): FireDispatchResult;

    public function requestApproval(
        ScheduleId            $id,
        ?string               $tenantId,
        ApprovalAction        $action,
        UserIdentityInterface $actor,
        ?string               $reason = null,
    ): ApprovalRequest;
}
