<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Exception;

final class SelfApprovalException extends FourEyesApprovalRequiredException
{
    public function __construct(public readonly string $approvalId, public readonly string $actorId)
    {
        parent::__construct(sprintf(
            'Identity "%s" cannot approve their own approval request "%s". '
            . '4-eyes requires a different approver.',
            $actorId,
            $approvalId,
        ));
    }
}
