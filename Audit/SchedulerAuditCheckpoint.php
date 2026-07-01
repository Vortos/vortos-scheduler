<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit;

/**
 * Immutable checkpoint record for a scheduler audit chain epoch.
 *
 * Every `$epochSize` entries (default 1000) the audit projector writes a checkpoint.
 * The checkpoint captures the cumulative hash at the epoch boundary and signs it with
 * HMAC so tampering is detectable. `SchedulerAuditChainVerifier` can verify epochs
 * independently using checkpoints, reducing chain-verify time from O(n) to O(n/epochSize).
 */
final readonly class SchedulerAuditCheckpoint
{
    public function __construct(
        public string $checkpointId,
        public string $chainKey,
        public int    $epoch,
        public int    $entryCount,
        public int    $lastSequence,
        public string $cumulativeHash,
        public string $hmac,
        public string $createdAt,
    ) {}
}
