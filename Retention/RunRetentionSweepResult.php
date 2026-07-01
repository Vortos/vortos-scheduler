<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Retention;

/** Outcome of one full RunRetentionSweeper::sweep() call, across all tenants. */
final readonly class RunRetentionSweepResult
{
    public function __construct(
        public int  $deletedCount,
        public bool $truncated,
    ) {}
}
