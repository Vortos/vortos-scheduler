<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Exception;

final class CommandNotAllowlistedException extends \RuntimeException
{
    public function __construct(public readonly string $commandClass)
    {
        parent::__construct(sprintf(
            'Command "%s" is not allowlisted for scheduling. '
            . 'Add #[SchedulableCommand] to the class to permit it.',
            $commandClass,
        ));
    }
}
