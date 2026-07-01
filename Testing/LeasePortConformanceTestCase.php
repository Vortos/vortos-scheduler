<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Lease\Exception\LeaseNotOwnedException;
use Vortos\Scheduler\Lease\Exception\LeaseRenewExpiredException;
use Vortos\Scheduler\Lease\Lease;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Lease\LeaseToken;

abstract class LeasePortConformanceTestCase extends TestCase
{
    private MutableClock $clock;
    private LeasePort $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = $this->createClock();
        $this->store = $this->createStore();
    }

    abstract protected function createStore(): LeasePort;

    abstract protected function createClock(): MutableClock;

    protected function supportsExplicitTtlExpiry(): bool
    {
        return true;
    }

    protected function supportsConcurrentAcquire(): bool
    {
        return true;
    }

    protected function tckKey(string $suffix = 'a'): string
    {
        return 'tck-' . $suffix;
    }

    // ─────────────────────────────────────────────
    // Group A — Acquire
    // ─────────────────────────────────────────────

    final public function test_acquire_returns_lease_on_empty_key(): void
    {
        $token = LeaseToken::generate();
        $lease = $this->store->acquire($this->tckKey(), $token, 30);

        self::assertInstanceOf(Lease::class, $lease);
    }

    final public function test_lease_key_matches_requested_key(): void
    {
        $key   = $this->tckKey('key-match');
        $token = LeaseToken::generate();
        $lease = $this->store->acquire($key, $token, 30);

        self::assertNotNull($lease);
        self::assertSame($key, $lease->key);
    }

    final public function test_lease_token_matches_supplied_token(): void
    {
        $token = LeaseToken::generate();
        $lease = $this->store->acquire($this->tckKey('token-match'), $token, 30);

        self::assertNotNull($lease);
        self::assertTrue($lease->isOwnedBy($token));
    }

    final public function test_lease_expires_at_is_approximately_now_plus_ttl(): void
    {
        $ttl   = 30;
        $token = LeaseToken::generate();
        $lease = $this->store->acquire($this->tckKey('expiry-approx'), $token, $ttl);

        self::assertNotNull($lease);

        $now       = $this->clock->now();
        $expected  = $now->modify("+{$ttl} seconds");
        $diff      = abs($lease->expiresAt->getTimestamp() - $expected->getTimestamp());

        self::assertLessThanOrEqual(2, $diff, 'expiresAt should be within 2 seconds of now+TTL');
    }

    final public function test_acquire_second_time_returns_null_while_lease_held(): void
    {
        $key    = $this->tckKey('mutex');
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $leaseA = $this->store->acquire($key, $tokenA, 30);
        self::assertNotNull($leaseA);

        $leaseB = $this->store->acquire($key, $tokenB, 30);
        self::assertNull($leaseB);
    }

    final public function test_acquire_different_keys_are_independent(): void
    {
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $leaseA = $this->store->acquire($this->tckKey('ind-a'), $tokenA, 30);
        $leaseB = $this->store->acquire($this->tckKey('ind-b'), $tokenB, 30);

        self::assertNotNull($leaseA);
        self::assertNotNull($leaseB);
    }

    // ─────────────────────────────────────────────
    // Group B — Expiry (skipped if driver uses real-time TTL)
    // ─────────────────────────────────────────────

    final public function test_expired_lease_can_be_reacquired(): void
    {
        if (!$this->supportsExplicitTtlExpiry()) {
            $this->markTestSkipped('Driver uses real-time TTL; cannot fast-forward with MutableClock.');
        }

        $key    = $this->tckKey('expiry-reacq');
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $leaseA = $this->store->acquire($key, $tokenA, 5);
        self::assertNotNull($leaseA);

        $this->clock->advanceSeconds(10);

        $leaseB = $this->store->acquire($key, $tokenB, 30);
        self::assertNotNull($leaseB, 'Should be re-acquirable after expiry');
    }

    final public function test_expired_lease_can_be_reacquired_by_different_token(): void
    {
        if (!$this->supportsExplicitTtlExpiry()) {
            $this->markTestSkipped('Driver uses real-time TTL; cannot fast-forward with MutableClock.');
        }

        $key    = $this->tckKey('expiry-diff-token');
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $this->store->acquire($key, $tokenA, 5);
        $this->clock->advanceSeconds(10);

        $leaseB = $this->store->acquire($key, $tokenB, 30);
        self::assertNotNull($leaseB);
        self::assertTrue($leaseB->isOwnedBy($tokenB));
    }

    final public function test_not_yet_expired_blocks_reacquire(): void
    {
        if (!$this->supportsExplicitTtlExpiry()) {
            $this->markTestSkipped('Driver uses real-time TTL; cannot fast-forward with MutableClock.');
        }

        $key    = $this->tckKey('not-expired');
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $this->store->acquire($key, $tokenA, 5);
        $this->clock->advanceSeconds(4);

        $leaseB = $this->store->acquire($key, $tokenB, 30);
        self::assertNull($leaseB, 'Should still be blocked 1 second before expiry');
    }

    // ─────────────────────────────────────────────
    // Group C — Renew
    // ─────────────────────────────────────────────

    final public function test_renew_returns_new_lease_with_later_expiry(): void
    {
        $key   = $this->tckKey('renew-expiry');
        $token = LeaseToken::generate();
        $lease = $this->store->acquire($key, $token, 10);
        self::assertNotNull($lease);

        $this->clock->advanceSeconds(5);
        $renewed = $this->store->renew($lease, 20);

        self::assertGreaterThan($lease->expiresAt, $renewed->expiresAt);
    }

    final public function test_renew_does_not_mutate_original_lease(): void
    {
        $key           = $this->tckKey('renew-immutable');
        $token         = LeaseToken::generate();
        $lease         = $this->store->acquire($key, $token, 10);
        self::assertNotNull($lease);

        $originalExpiry = $lease->expiresAt;
        $this->store->renew($lease, 30);

        self::assertEquals($originalExpiry, $lease->expiresAt);
    }

    final public function test_renew_with_wrong_token_throws_not_owned(): void
    {
        $key        = $this->tckKey('renew-wrong-token');
        $realToken  = LeaseToken::generate();
        $fakeToken  = LeaseToken::generate();
        $realLease  = $this->store->acquire($key, $realToken, 30);
        self::assertNotNull($realLease);

        $fakeLease = new Lease($key, $fakeToken, $realLease->acquiredAt, $realLease->expiresAt);

        $this->expectException(LeaseNotOwnedException::class);
        $this->store->renew($fakeLease, 30);
    }

    final public function test_renew_on_expired_lease_throws_expired(): void
    {
        if (!$this->supportsExplicitTtlExpiry()) {
            $this->markTestSkipped('Driver uses real-time TTL; cannot fast-forward with MutableClock.');
        }

        $key   = $this->tckKey('renew-expired');
        $token = LeaseToken::generate();
        $lease = $this->store->acquire($key, $token, 5);
        self::assertNotNull($lease);

        $this->clock->advanceSeconds(10);

        $this->expectException(LeaseRenewExpiredException::class);
        $this->store->renew($lease, 30);
    }

    final public function test_after_renew_competing_acquire_still_blocked(): void
    {
        $key    = $this->tckKey('renew-block');
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $leaseA = $this->store->acquire($key, $tokenA, 10);
        self::assertNotNull($leaseA);

        $this->store->renew($leaseA, 20);

        $leaseB = $this->store->acquire($key, $tokenB, 10);
        self::assertNull($leaseB, 'Competing acquire must still be blocked after renew');
    }

    // ─────────────────────────────────────────────
    // Group D — Release
    // ─────────────────────────────────────────────

    final public function test_release_allows_immediate_reacquire(): void
    {
        $key    = $this->tckKey('release-reacq');
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $leaseA = $this->store->acquire($key, $tokenA, 30);
        self::assertNotNull($leaseA);

        $this->store->release($leaseA);

        $leaseB = $this->store->acquire($key, $tokenB, 30);
        self::assertNotNull($leaseB);
    }

    final public function test_release_of_expired_lease_is_idempotent_no_op(): void
    {
        if (!$this->supportsExplicitTtlExpiry()) {
            $this->markTestSkipped('Driver uses real-time TTL; cannot fast-forward with MutableClock.');
        }

        $key   = $this->tckKey('release-expired');
        $token = LeaseToken::generate();
        $lease = $this->store->acquire($key, $token, 5);
        self::assertNotNull($lease);

        $this->clock->advanceSeconds(10);

        $this->store->release($lease);
        $this->addToAssertionCount(1);
    }

    final public function test_release_with_wrong_token_throws_not_owned(): void
    {
        $key       = $this->tckKey('release-wrong-token');
        $realToken = LeaseToken::generate();
        $fakeToken = LeaseToken::generate();

        $realLease = $this->store->acquire($key, $realToken, 30);
        self::assertNotNull($realLease);

        $fakeLease = new Lease($key, $fakeToken, $realLease->acquiredAt, $realLease->expiresAt);

        $this->expectException(LeaseNotOwnedException::class);
        $this->store->release($fakeLease);
    }

    final public function test_mutual_exclusion_acquire_release_acquire_cycle(): void
    {
        $key    = $this->tckKey('mutex-cycle');
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();
        $tokenC = LeaseToken::generate();

        $leaseA = $this->store->acquire($key, $tokenA, 30);
        self::assertNotNull($leaseA);

        self::assertNull($this->store->acquire($key, $tokenB, 30));

        $this->store->release($leaseA);

        $leaseC = $this->store->acquire($key, $tokenC, 30);
        self::assertNotNull($leaseC);
        self::assertTrue($leaseC->isOwnedBy($tokenC));
    }
}
