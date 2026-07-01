<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security;

use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalRequest;

interface FourEyesGateInterface
{
    public function requiresApproval(Schedule $schedule): bool;

    public function requestApproval(
        Schedule       $schedule,
        ApprovalAction $action,
        string         $requestedBy,
        ?string        $reason = null,
    ): ApprovalRequest;

    public function approve(string $approvalId, string $approvedBy): ApprovalRequest;

    public function reject(string $approvalId, string $rejectedBy): ApprovalRequest;

    public function assertApproved(Schedule $schedule, ApprovalAction $action): void;
}
