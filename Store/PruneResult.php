<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Store;

/**
 * Outcome of one pruneOldRuns() call.
 *
 * $truncated = true means the wall-clock budget (pruneMaxDurationSec) was
 * exhausted while more eligible rows may still remain — a normal, expected
 * outcome on a large first-run backlog, not an error. The next scheduled or
 * manual prune picks up where this one left off.
 */
final readonly class PruneResult
{
    public function __construct(
        public int  $deletedCount,
        public bool $truncated,
    ) {}
}
