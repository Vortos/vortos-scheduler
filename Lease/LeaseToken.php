<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease;

final readonly class LeaseToken
{
    private function __construct(public readonly string $value) {}

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public static function fromString(string $value): self
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $value)) {
            throw new \InvalidArgumentException(
                sprintf('LeaseToken must be 32 lowercase hex chars, got: "%s".', $value)
            );
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
