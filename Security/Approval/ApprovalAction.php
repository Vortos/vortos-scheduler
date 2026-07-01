<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Approval;

enum ApprovalAction: string
{
    case Activate = 'activate';
    case RunNow   = 'run-now';
}
