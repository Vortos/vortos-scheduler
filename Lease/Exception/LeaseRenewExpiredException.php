<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease\Exception;

final class LeaseRenewExpiredException extends LeaseException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('Cannot renew lease "%s": it has already expired', $key));
    }
}
