<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Exception;

final class ScheduleAccessDeniedException extends \RuntimeException
{
    public function __construct(
        public readonly string $action,
        public readonly string $identityId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Identity "%s" is not permitted to perform scheduler action "%s".',
                $identityId,
                $action,
            ),
            0,
            $previous,
        );
    }
}
