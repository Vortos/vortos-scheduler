<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Lease;

/**
 * Tracks per-shard lease heartbeat health.
 *
 * The daemon renews each held lease at TTL/3 intervals. If the renewal fails
 * silently (network blip, Redis restart), the lease eventually expires and another
 * node takes over — which is safe because idempotency is guaranteed by the slot
 * unique constraint, not the lease.
 *
 * However, if the heartbeat stops but the lease has not yet expired, the daemon
 * continues dispatching under the mistaken belief it holds the lease. This is still
 * correct (slot idempotency saves us) but produces audit noise and unnecessary
 * contention. This guard detects the silent-heartbeat failure and causes the daemon
 * to voluntarily step down before the lease expires.
 *
 * Threshold: 90 % of the TTL since last successful heartbeat. This provides an 10 %
 * margin before the real TTL expires, during which the daemon stops dispatching and
 * lets the lease expire naturally. Another node picks up within one TTL window.
 *
 * Special case: a shard that has never had a heartbeat recorded (just acquired) is
 * considered healthy for the first full TTL window.
 */
final class LeaseHeartbeatGuard
{
    /** @var array<int, \DateTimeImmutable> shard → last successful renewal timestamp */
    private array $lastHeartbeat = [];

    /**
     * Record a successful lease renewal for a shard.
     */
    public function recordHeartbeat(int $shardIndex, \DateTimeImmutable $at): void
    {
        $this->lastHeartbeat[$shardIndex] = $at;
    }

    /**
     * Returns true if the heartbeat is considered healthy for this shard.
     *
     * Healthy = last recorded heartbeat is within 90 % of $leaseTtlSec seconds ago,
     * OR no heartbeat has been recorded yet (shard was just acquired).
     */
    public function isHealthy(int $shardIndex, \DateTimeImmutable $now, int $leaseTtlSec): bool
    {
        $last = $this->lastHeartbeat[$shardIndex] ?? null;

        if ($last === null) {
            return true;
        }

        $threshold = (int) ($leaseTtlSec * 0.9);

        return ($now->getTimestamp() - $last->getTimestamp()) < $threshold;
    }

    /**
     * Clear the heartbeat record for a shard (e.g., after lease release).
     */
    public function clear(int $shardIndex): void
    {
        unset($this->lastHeartbeat[$shardIndex]);
    }

    /**
     * Clear all shard records.
     */
    public function clearAll(): void
    {
        $this->lastHeartbeat = [];
    }
}
