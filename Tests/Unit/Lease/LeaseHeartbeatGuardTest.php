<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Lease;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Lease\LeaseHeartbeatGuard;

/**
 * Unit tests for LeaseHeartbeatGuard (E2).
 *
 * The guard tracks the last successful lease renewal per shard. If no renewal is
 * recorded within 90% of the TTL the shard is declared unhealthy and the daemon
 * should voluntarily skip dispatch for that shard.
 */
final class LeaseHeartbeatGuardTest extends TestCase
{
    private LeaseHeartbeatGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new LeaseHeartbeatGuard();
    }

    public function test_shard_with_no_prior_heartbeat_is_healthy(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');

        self::assertTrue(
            $this->guard->isHealthy(0, $now, 30),
            'A freshly-acquired shard with no heartbeat record should be considered healthy',
        );
    }

    public function test_shard_healthy_within_90_pct_ttl(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $this->guard->recordHeartbeat(0, $now);

        // 26 seconds later — 26/30 = ~87% < 90%
        $later = $now->modify('+26 seconds');

        self::assertTrue($this->guard->isHealthy(0, $later, 30));
    }

    public function test_shard_unhealthy_beyond_90_pct_ttl(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $this->guard->recordHeartbeat(0, $now);

        // 28 seconds later — 28/30 = ~93% > 90%
        $later = $now->modify('+28 seconds');

        self::assertFalse(
            $this->guard->isHealthy(0, $later, 30),
            'Shard should be unhealthy when last heartbeat is > 90% of TTL ago',
        );
    }

    public function test_exact_90_pct_boundary_is_unhealthy(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $this->guard->recordHeartbeat(0, $now);

        // Exactly 27 seconds later — 27/30 = 90%
        $atBoundary = $now->modify('+27 seconds');

        self::assertFalse(
            $this->guard->isHealthy(0, $atBoundary, 30),
            'At exactly the 90% boundary, shard should be considered unhealthy',
        );
    }

    public function test_heartbeat_renewal_resets_health(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $this->guard->recordHeartbeat(0, $now);

        // Push to unhealthy
        $later = $now->modify('+29 seconds');
        self::assertFalse($this->guard->isHealthy(0, $later, 30));

        // Renew heartbeat
        $this->guard->recordHeartbeat(0, $later);

        // Check health 1 second after renewal — should be healthy again
        self::assertTrue($this->guard->isHealthy(0, $later->modify('+1 second'), 30));
    }

    public function test_multiple_shards_tracked_independently(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $this->guard->recordHeartbeat(0, $now);
        // Shard 1 not recorded

        $late = $now->modify('+29 seconds');

        self::assertFalse($this->guard->isHealthy(0, $late, 30));
        self::assertTrue($this->guard->isHealthy(1, $late, 30), 'Shard with no heartbeat should be healthy');
    }

    public function test_clear_shard_resets_to_healthy(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $this->guard->recordHeartbeat(0, $now);

        $late = $now->modify('+29 seconds');
        self::assertFalse($this->guard->isHealthy(0, $late, 30));

        $this->guard->clear(0);

        self::assertTrue($this->guard->isHealthy(0, $late, 30), 'After clear(), shard should be healthy (no prior record)');
    }

    public function test_clear_all_resets_all_shards(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $this->guard->recordHeartbeat(0, $now);
        $this->guard->recordHeartbeat(1, $now);
        $this->guard->recordHeartbeat(2, $now);

        $late = $now->modify('+29 seconds');
        self::assertFalse($this->guard->isHealthy(0, $late, 30));

        $this->guard->clearAll();

        self::assertTrue($this->guard->isHealthy(0, $late, 30));
        self::assertTrue($this->guard->isHealthy(1, $late, 30));
        self::assertTrue($this->guard->isHealthy(2, $late, 30));
    }

    public function test_large_ttl_computes_threshold_correctly(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $this->guard->recordHeartbeat(0, $now);

        // TTL = 3600s; 90% = 3240s
        $just_under = $now->modify('+3239 seconds');
        $just_over  = $now->modify('+3241 seconds');

        self::assertTrue($this->guard->isHealthy(0, $just_under, 3600));
        self::assertFalse($this->guard->isHealthy(0, $just_over, 3600));
    }

    public function test_same_shard_multiple_heartbeats_uses_latest(): void
    {
        $t0 = new DateTimeImmutable('2026-07-01T10:00:00Z');
        $t1 = $t0->modify('+10 seconds');

        $this->guard->recordHeartbeat(0, $t0);
        $this->guard->recordHeartbeat(0, $t1); // More recent renewal

        // 20s after t1 = 20/30 < 90% — healthy
        $check = $t1->modify('+20 seconds');

        self::assertTrue($this->guard->isHealthy(0, $check, 30));
    }
}
