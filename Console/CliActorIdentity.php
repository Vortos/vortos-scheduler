<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Console;

use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Minimal UserIdentity for CLI commands where there is no ambient HTTP request.
 *
 * CLI operators authenticate via SSH or host-level auth. The actor ID is
 * supplied via the --actor option. For production, wire the real
 * UserIdentityInterface from the HTTP layer.
 */
final readonly class CliActorIdentity implements UserIdentityInterface
{
    public function __construct(private string $actorId) {}

    public function id(): string
    {
        return $this->actorId;
    }

    public function roles(): array
    {
        return [];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function hasRole(string $role): bool
    {
        return false;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function getClaims(): array
    {
        return [];
    }
}
