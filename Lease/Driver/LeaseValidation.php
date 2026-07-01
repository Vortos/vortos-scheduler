<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease\Driver;

trait LeaseValidation
{
    private function validateTtl(int $ttl): void
    {
        if ($ttl <= 0) {
            throw new \InvalidArgumentException(
                sprintf('Lease TTL must be a positive integer, got %d.', $ttl)
            );
        }
    }

    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Lease key must not be empty.');
        }

        if (strlen($key) > 200) {
            throw new \InvalidArgumentException(
                sprintf('Lease key must be <= 200 chars, got %d.', strlen($key))
            );
        }

        if (!preg_match('/^[a-z0-9][a-z0-9:_\-]*$/', $key)) {
            throw new \InvalidArgumentException(
                sprintf('Lease key "%s" contains invalid characters. Must match ^[a-z0-9][a-z0-9:_\\-]*$.', $key)
            );
        }
    }
}
