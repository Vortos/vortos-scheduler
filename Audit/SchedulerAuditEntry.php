<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit;

/**
 * One append-only, hash-chained, HMAC-signed row in the scheduler audit ledger (S8).
 *
 * `contentHash = sha256(canonicalJson(hashableFields) . prevHash)` chains each entry to
 * its predecessor so any silent rewrite of a past row changes every hash after it.
 * `signature = HMAC-SHA256(signingMessage, key)` proves authorship.
 *
 * Analogous to {@see \Vortos\Observability\Audit\AuditEntry} but uses scheduler-specific
 * fields (tenantId, scheduleId, slot, shardIndex) instead of deploy-specific ones.
 * Both reuse the pure {@see \Vortos\Observability\Audit\AuditHashChain} primitives.
 */
final readonly class SchedulerAuditEntry
{
    /**
     * @param array<string, mixed> $data Scrubbed event-specific payload — no secrets/PII
     */
    public function __construct(
        public string  $entryId,
        public int     $sequence,
        public string  $eventType,       // SchedulerAuditEvent::value
        public string  $actorId,         // userId or 'system'
        public ?string $tenantId,        // null = system-wide
        public ?string $scheduleId,      // present on fire + mutation events
        public ?string $slot,            // present on fire events
        public ?int    $shardIndex,      // present on leader events
        public string  $occurredAt,      // RFC3339
        public array   $data,
        public string  $chainKey,        // "scheduler:{tenantId ?? 'system'}:{env}"
        public string  $prevHash,        // predecessor contentHash (genesis = sha256(''))
        public string  $contentHash,     // sha256(canonicalJson(hashableFields) . prevHash)
        public string  $signature,       // HMAC-SHA256(signingMessage, hmacKey)
    ) {
        if ($sequence < 0) {
            throw new \InvalidArgumentException('Audit sequence must be >= 0.');
        }

        if ($entryId === '') {
            throw new \InvalidArgumentException('Audit entryId must not be empty.');
        }

        if ($chainKey === '') {
            throw new \InvalidArgumentException('Audit chainKey must not be empty.');
        }
    }

    /**
     * Fields covered by the content hash — everything except the derived chain/signature
     * fields (prevHash / contentHash / signature), which are computed FROM this set.
     *
     * @return array<string, mixed>
     */
    public function hashableFields(): array
    {
        return [
            'entry_id'    => $this->entryId,
            'sequence'    => $this->sequence,
            'event_type'  => $this->eventType,
            'actor_id'    => $this->actorId,
            'tenant_id'   => $this->tenantId,
            'schedule_id' => $this->scheduleId,
            'slot'        => $this->slot,
            'shard_index' => $this->shardIndex,
            'occurred_at' => $this->occurredAt,
            'data'        => $this->data,
            'chain_key'   => $this->chainKey,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entry_id'     => $this->entryId,
            'sequence'     => $this->sequence,
            'event_type'   => $this->eventType,
            'actor_id'     => $this->actorId,
            'tenant_id'    => $this->tenantId,
            'schedule_id'  => $this->scheduleId,
            'slot'         => $this->slot,
            'shard_index'  => $this->shardIndex,
            'occurred_at'  => $this->occurredAt,
            'data'         => $this->data,
            'chain_key'    => $this->chainKey,
            'prev_hash'    => $this->prevHash,
            'content_hash' => $this->contentHash,
            'signature'    => $this->signature,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            entryId:    (string) $row['entry_id'],
            sequence:   (int)    $row['sequence'],
            eventType:  (string) $row['event_type'],
            actorId:    (string) $row['actor_id'],
            tenantId:   isset($row['tenant_id']) ? (string) $row['tenant_id'] : null,
            scheduleId: isset($row['schedule_id']) ? (string) $row['schedule_id'] : null,
            slot:       isset($row['slot']) ? (string) $row['slot'] : null,
            shardIndex: isset($row['shard_index']) ? (int) $row['shard_index'] : null,
            occurredAt: (string) $row['occurred_at'],
            data:       is_string($row['data'])
                            ? (array) json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR)
                            : (array) $row['data'],
            chainKey:   (string) $row['chain_key'],
            prevHash:   (string) $row['prev_hash'],
            contentHash: (string) $row['content_hash'],
            signature:  (string) $row['signature'],
        );
    }
}
