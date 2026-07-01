<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule\Policy;

use InvalidArgumentException;

/**
 * Optional dispatch-time splay to prevent thundering-herd when many schedules
 * fire at the same instant.
 *
 * The offset is deterministic per (slotKey, nodeId): same slot on the same node
 * always gets the same offset, stable across re-scans. This means the actual
 * dispatch instant is predictable and auditable for any given (slot, node) pair.
 *
 * crc32 is fast and provides adequate splay distribution for this use case;
 * it is not a security primitive.
 */
final readonly class Jitter
{
    public const MIN_WINDOW_SECONDS = 1;
    public const MAX_WINDOW_SECONDS = 3600;

    public function __construct(public readonly int $windowSeconds)
    {
        if ($windowSeconds < self::MIN_WINDOW_SECONDS || $windowSeconds > self::MAX_WINDOW_SECONDS) {
            throw new InvalidArgumentException(
                sprintf(
                    'Jitter window must be %d–%d seconds, got %d.',
                    self::MIN_WINDOW_SECONDS,
                    self::MAX_WINDOW_SECONDS,
                    $windowSeconds,
                )
            );
        }
    }

    /**
     * Deterministic splay offset in seconds for this slot on this node.
     * Always in [0, windowSeconds). Safe to call multiple times with the same args.
     */
    public function offsetSeconds(string $slotKey, string $nodeId): int
    {
        $hash = crc32($slotKey . ':' . $nodeId);

        return abs($hash) % $this->windowSeconds;
    }
}
