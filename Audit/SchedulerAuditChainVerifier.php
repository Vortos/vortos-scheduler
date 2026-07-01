<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit;

use Vortos\Observability\Audit\AuditHashChain;

/**
 * Walks a sequence of {@see SchedulerAuditEntry} rows (ordered by sequence for one
 * chain_key) and recomputes the hash chain + HMAC signatures, detecting:
 *
 *  - a mutated entry (content hash no longer matches its recorded fields)
 *  - a forged/invalid signature (HMAC mismatch)
 *  - a broken link (an entry's prevHash no longer matches its predecessor's contentHash)
 *  - a sequence gap (a row missing or out of order)
 *
 * Pure and read-only — never mutates the ledger. Used by scheduler:doctor (S9)
 * to verify the most-recent tail of each chain.
 */
final class SchedulerAuditChainVerifier
{
    public function __construct(
        private readonly AuditHashChain $chain = new AuditHashChain(),
    ) {}

    /**
     * @param list<SchedulerAuditEntry> $entries Ordered by sequence ASC for a single chain_key
     */
    public function verify(array $entries, string $hmacKey): SchedulerChainVerificationResult
    {
        $expectedPrevHash = AuditHashChain::GENESIS_HASH;
        $expectedSequence = null;

        foreach ($entries as $entry) {
            // Sequence must be gapless
            if ($expectedSequence !== null && $entry->sequence !== $expectedSequence) {
                return SchedulerChainVerificationResult::broken(
                    $entry->sequence,
                    (string) $expectedSequence,
                    (string) $entry->sequence,
                    'sequence gap or reordering',
                );
            }

            // prevHash must link to the predecessor's contentHash
            if ($entry->prevHash !== $expectedPrevHash) {
                return SchedulerChainVerificationResult::broken(
                    $entry->sequence,
                    $expectedPrevHash,
                    $entry->prevHash,
                    'prevHash does not match predecessor contentHash (truncated or reordered tail)',
                );
            }

            // Content hash must match hashableFields
            $recomputedContentHash = $this->chain->contentHash($entry->hashableFields(), $entry->prevHash);
            if (!hash_equals($recomputedContentHash, $entry->contentHash)) {
                return SchedulerChainVerificationResult::broken(
                    $entry->sequence,
                    $recomputedContentHash,
                    $entry->contentHash,
                    'content hash mismatch (entry was mutated)',
                );
            }

            // HMAC signature must be valid
            $signingMessage = $this->chain->signingMessage(
                $entry->entryId,
                $entry->sequence,
                $entry->contentHash,
                $entry->prevHash,
            );

            if (!$this->chain->verifySignature($signingMessage, $entry->signature, $hmacKey)) {
                return SchedulerChainVerificationResult::broken(
                    $entry->sequence,
                    $this->chain->sign($signingMessage, $hmacKey),
                    $entry->signature,
                    'HMAC signature invalid (forged or signed with a different key)',
                );
            }

            $expectedPrevHash = $entry->contentHash;
            $expectedSequence = $entry->sequence + 1;
        }

        return SchedulerChainVerificationResult::intact();
    }
}
