<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease\Exception;

final class LeaseNotOwnedException extends LeaseException
{
    public function __construct(string $key, string $detail = '')
    {
        $message = sprintf('Lease "%s" is not owned by the calling token', $key);

        if ($detail !== '') {
            $message .= ': ' . $detail;
        }

        parent::__construct($message);
    }
}
