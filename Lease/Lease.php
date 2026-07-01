<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease;

use DateTimeImmutable;

final readonly class Lease
{
    public function __construct(
        public string            $key,
        public LeaseToken        $token,
        public DateTimeImmutable $acquiredAt,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isOwnedBy(LeaseToken $candidate): bool
    {
        return $this->token->equals($candidate);
    }

    public function withExtendedExpiry(DateTimeImmutable $newExpiresAt): self
    {
        return new self($this->key, $this->token, $this->acquiredAt, $newExpiresAt);
    }
}
