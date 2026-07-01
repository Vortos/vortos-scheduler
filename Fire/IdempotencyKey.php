<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Fire;

/**
 * The dedupe key for a single scheduled fire: sha256(slotKey).
 *
 * Stored as a 64-character lowercase hex string in the fire-ledger unique constraint.
 * Inserting a duplicate key fails the INSERT — this is the exactly-once-effect mechanism.
 *
 * Fixed-length hex avoids any timezone-quoting or character-encoding ambiguity
 * that the raw slot key string would introduce in the unique constraint.
 */
final readonly class IdempotencyKey
{
    private function __construct(public readonly string $value) {}

    public static function fromSlotKey(string $slotKey): self
    {
        return new self(hash('sha256', $slotKey));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
