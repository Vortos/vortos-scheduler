<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Writes per-epoch HMAC-signed checkpoints into the audit chain.
 *
 * Called by {@see SchedulerAuditProjector} every `$epochSize` entries. A checkpoint
 * records the cumulative hash at the epoch boundary, allowing `SchedulerAuditChainVerifier`
 * to skip earlier epochs without replaying every entry from genesis.
 *
 * Checkpoint integrity: `hmac = HMAC-SHA256(key, "{epoch}:{entryCount}:{lastSequence}:{cumulativeHash}")`.
 * Any tampering with the checkpoint body invalidates its HMAC; verifying all checkpoint
 * HMACs takes O(n/epochSize) time, far cheaper than full-chain traversal.
 */
final class SchedulerAuditCheckpointProjector
{
    public function __construct(
        private readonly SchedulerAuditCheckpointRepositoryInterface $repository,
        private readonly string                                       $hmacKey,
        private readonly int                                          $epochSize = 1000,
    ) {
        if ($epochSize < 1) {
            throw new \InvalidArgumentException("epochSize must be >= 1, got {$epochSize}.");
        }
    }

    /**
     * Conditionally write a checkpoint if this $sequence is an epoch boundary.
     *
     * Called after each audit entry is appended. When $sequence % $epochSize === 0
     * (and sequence > 0), writes a checkpoint for the completed epoch.
     *
     * @param string $chainKey        The audit chain key (tenant-scoped)
     * @param int    $sequence        Sequence number of the entry just written
     * @param string $cumulativeHash  contentHash of the entry just written
     */
    public function maybeCheckpoint(string $chainKey, int $sequence, string $cumulativeHash): void
    {
        if ($sequence <= 0 || ($sequence % $this->epochSize) !== 0) {
            return;
        }

        $epoch        = intdiv($sequence, $this->epochSize);
        $entryCount   = $this->epochSize;
        $createdAt    = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $checkpointId = $this->generateId();

        $signingMessage = "{$epoch}:{$entryCount}:{$sequence}:{$cumulativeHash}";
        $hmac = hash_hmac('sha256', $signingMessage, $this->hmacKey);

        $checkpoint = new SchedulerAuditCheckpoint(
            checkpointId:   $checkpointId,
            chainKey:       $chainKey,
            epoch:          $epoch,
            entryCount:     $entryCount,
            lastSequence:   $sequence,
            cumulativeHash: $cumulativeHash,
            hmac:           $hmac,
            createdAt:      $createdAt,
        );

        $this->repository->save($checkpoint);
    }

    public function verifyCheckpoint(SchedulerAuditCheckpoint $checkpoint): bool
    {
        $signingMessage = "{$checkpoint->epoch}:{$checkpoint->entryCount}:{$checkpoint->lastSequence}:{$checkpoint->cumulativeHash}";
        $expected       = hash_hmac('sha256', $signingMessage, $this->hmacKey);

        return hash_equals($expected, $checkpoint->hmac);
    }

    public function getEpochSize(): int
    {
        return $this->epochSize;
    }

    private function generateId(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
