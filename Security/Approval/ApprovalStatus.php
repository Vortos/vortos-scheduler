<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Approval;

enum ApprovalStatus: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired  = 'expired';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Approved, self::Rejected, self::Expired => true,
            self::Pending => false,
        };
    }
}
