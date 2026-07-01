<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit;

final readonly class SchedulerChainVerificationResult
{
    private function __construct(
        public bool    $intact,
        public ?int    $brokenAtSequence,
        public ?string $expected,
        public ?string $actual,
        public ?string $reason,
    ) {}

    public static function intact(): self
    {
        return new self(true, null, null, null, null);
    }

    public static function broken(int $sequence, string $expected, string $actual, string $reason): self
    {
        return new self(false, $sequence, $expected, $actual, $reason);
    }
}
