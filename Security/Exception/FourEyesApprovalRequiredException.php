<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Exception;

use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;

class FourEyesApprovalRequiredException extends \RuntimeException
{
    public static function notRequested(string $scheduleId, ApprovalAction $action): self
    {
        return new self(sprintf(
            'Schedule "%s" requires 4-eyes approval for action "%s" but no approval has been requested.',
            $scheduleId,
            $action->value,
        ));
    }

    public static function notApproved(string $scheduleId, ApprovalAction $action, ApprovalStatus $status): self
    {
        return new self(sprintf(
            'Schedule "%s" requires 4-eyes approval for action "%s" but the request is in status "%s".',
            $scheduleId,
            $action->value,
            $status->value,
        ));
    }

    public static function expired(string $approvalId): self
    {
        return new self(sprintf(
            'Approval request "%s" has expired. Request a new approval.',
            $approvalId,
        ));
    }

    public static function alreadyResolved(string $approvalId, ApprovalStatus $status): self
    {
        return new self(sprintf(
            'Approval request "%s" is already in terminal status "%s" and cannot be re-resolved.',
            $approvalId,
            $status->value,
        ));
    }
}
