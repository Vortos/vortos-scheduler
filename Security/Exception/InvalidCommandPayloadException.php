<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Exception;

final class InvalidCommandPayloadException extends \RuntimeException
{
    public function __construct(string $commandClass, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Invalid payload for command "%s": %s', $commandClass, $reason),
            0,
            $previous,
        );
    }
}
