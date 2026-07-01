<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Lease;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Lease\Lease;
use Vortos\Scheduler\Lease\LeaseToken;

final class LeaseTest extends TestCase
{
    private function makeLease(
        string $key = 'test-key',
        ?LeaseToken $token = null,
        string $acquiredAt = '2026-07-01T00:00:00Z',
        string $expiresAt = '2026-07-01T00:00:30Z',
    ): Lease {
        return new Lease(
            $key,
            $token ?? LeaseToken::generate(),
            new DateTimeImmutable($acquiredAt),
            new DateTimeImmutable($expiresAt),
        );
    }

    public function test_is_expired_before_expiry_returns_false(): void
    {
        $lease = $this->makeLease(expiresAt: '2026-07-01T00:00:30Z');
        $now   = new DateTimeImmutable('2026-07-01T00:00:20Z');

        self::assertFalse($lease->isExpired($now));
    }

    public function test_is_expired_at_exact_boundary_returns_true(): void
    {
        $expiry = '2026-07-01T00:00:30Z';
        $lease  = $this->makeLease(expiresAt: $expiry);
        $now    = new DateTimeImmutable($expiry);

        self::assertTrue($lease->isExpired($now));
    }

    public function test_is_expired_after_expiry_returns_true(): void
    {
        $lease = $this->makeLease(expiresAt: '2026-07-01T00:00:30Z');
        $now   = new DateTimeImmutable('2026-07-01T00:01:00Z');

        self::assertTrue($lease->isExpired($now));
    }

    public function test_is_owned_by_matching_token_returns_true(): void
    {
        $token = LeaseToken::generate();
        $lease = $this->makeLease(token: $token);

        self::assertTrue($lease->isOwnedBy($token));
    }

    public function test_is_owned_by_different_token_returns_false(): void
    {
        $lease = $this->makeLease(token: LeaseToken::generate());

        self::assertFalse($lease->isOwnedBy(LeaseToken::generate()));
    }

    public function test_with_extended_expiry_returns_new_lease_with_new_expiry(): void
    {
        $lease     = $this->makeLease(expiresAt: '2026-07-01T00:00:30Z');
        $newExpiry = new DateTimeImmutable('2026-07-01T00:01:00Z');
        $extended  = $lease->withExtendedExpiry($newExpiry);

        self::assertEquals($newExpiry, $extended->expiresAt);
    }

    public function test_with_extended_expiry_does_not_mutate_original(): void
    {
        $originalExpiry = new DateTimeImmutable('2026-07-01T00:00:30Z');
        $lease          = $this->makeLease(expiresAt: '2026-07-01T00:00:30Z');

        $lease->withExtendedExpiry(new DateTimeImmutable('2026-07-01T00:01:00Z'));

        self::assertEquals($originalExpiry, $lease->expiresAt);
    }

    public function test_with_extended_expiry_preserves_key_and_token(): void
    {
        $token    = LeaseToken::generate();
        $lease    = $this->makeLease(key: 'my-key', token: $token);
        $extended = $lease->withExtendedExpiry(new DateTimeImmutable('2026-07-01T00:01:00Z'));

        self::assertSame('my-key', $extended->key);
        self::assertTrue($extended->isOwnedBy($token));
    }

    public function test_properties_accessible(): void
    {
        $token      = LeaseToken::generate();
        $acquiredAt = new DateTimeImmutable('2026-07-01T00:00:00Z');
        $expiresAt  = new DateTimeImmutable('2026-07-01T00:00:30Z');
        $lease      = new Lease('my-key', $token, $acquiredAt, $expiresAt);

        self::assertSame('my-key', $lease->key);
        self::assertSame($token, $lease->token);
        self::assertEquals($acquiredAt, $lease->acquiredAt);
        self::assertEquals($expiresAt, $lease->expiresAt);
    }
}
