<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Minimal UserIdentity stub for unit tests.
 * Grants no roles; always authenticated.
 */
final class FakeUserIdentity implements UserIdentityInterface
{
    public function __construct(private readonly string $userId = 'user-1') {}

    public function id(): string
    {
        return $this->userId;
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
