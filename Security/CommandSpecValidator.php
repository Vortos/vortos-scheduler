<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security;

use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Security\Exception\CommandNotAllowlistedException;

/**
 * Enforces the compile-time command allowlist at both create-time and dispatch-time.
 *
 * The allowlist is built by SchedulableCommandPass from all classes carrying
 * #[SchedulableCommand]. When no commands are allowlisted, this validator is not
 * registered (backward-compatible: existing schedules continue to work).
 *
 * FireDispatcher receives this as a nullable constructor parameter so that deployments
 * without any #[SchedulableCommand] classes run without a validator.
 */
final class CommandSpecValidator
{
    /** @param array<string, true> $allowlist FQCN → true */
    public function __construct(private readonly array $allowlist) {}

    public function assert(CommandSpec $spec): void
    {
        if (!isset($this->allowlist[$spec->commandClass])) {
            throw new CommandNotAllowlistedException($spec->commandClass);
        }
    }

    public function isAllowlisted(string $commandClass): bool
    {
        return isset($this->allowlist[$commandClass]);
    }

    /** @return list<string> */
    public function allowlistedClasses(): array
    {
        return array_keys($this->allowlist);
    }
}
