<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Cache\Adapter\RedisConnectionFactory;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Lease\Driver\RedisLeaseStore;
use Vortos\Scheduler\Lease\Exception\LeaseNotOwnedException;
use Vortos\Scheduler\Lease\Lease;
use Vortos\Scheduler\Lease\LeaseToken;

final class RedisLeaseStoreConcurrencyTest extends TestCase
{
    private \Redis $redisA;
    private \Redis $redisB;
    private RedisLeaseStore $storeA;
    private RedisLeaseStore $storeB;

    protected function setUp(): void
    {
        $this->redisA = $this->connectOrSkip();
        $this->redisB = $this->connectOrSkip();
        $this->storeA = new RedisLeaseStore($this->redisA, new MutableClock(new DateTimeImmutable()));
        $this->storeB = new RedisLeaseStore($this->redisB, new MutableClock(new DateTimeImmutable()));
        $this->cleanKeys();
    }

    protected function tearDown(): void
    {
        $this->cleanKeys();
    }

    public function test_two_nodes_only_one_acquires(): void
    {
        $key    = 'integ-mutex';
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $leaseA = $this->storeA->acquire($key, $tokenA, 30);
        self::assertNotNull($leaseA);

        $leaseB = $this->storeB->acquire($key, $tokenB, 30);
        self::assertNull($leaseB);
    }

    public function test_reacquire_after_release(): void
    {
        $key    = 'integ-release';
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $leaseA = $this->storeA->acquire($key, $tokenA, 30);
        self::assertNotNull($leaseA);

        $this->storeA->release($leaseA);

        $leaseB = $this->storeB->acquire($key, $tokenB, 30);
        self::assertNotNull($leaseB);
    }

    public function test_wrong_token_release_throws(): void
    {
        $key       = 'integ-release-other';
        $realToken = LeaseToken::generate();
        $fakeToken = LeaseToken::generate();

        $realLease = $this->storeA->acquire($key, $realToken, 30);
        self::assertNotNull($realLease);

        $fakeLease = new Lease($key, $fakeToken, $realLease->acquiredAt, $realLease->expiresAt);

        $this->expectException(LeaseNotOwnedException::class);
        $this->storeB->release($fakeLease);
    }

    public function test_wrong_token_renew_throws(): void
    {
        $key       = 'integ-renew-other';
        $realToken = LeaseToken::generate();
        $fakeToken = LeaseToken::generate();

        $realLease = $this->storeA->acquire($key, $realToken, 30);
        self::assertNotNull($realLease);

        $fakeLease = new Lease($key, $fakeToken, $realLease->acquiredAt, $realLease->expiresAt);

        $this->expectException(LeaseNotOwnedException::class);
        $this->storeB->renew($fakeLease, 30);
    }

    public function test_lua_release_script_is_atomic(): void
    {
        $key   = 'integ-lua-atomic';
        $token = LeaseToken::generate();

        $lease = $this->storeA->acquire($key, $token, 30);
        self::assertNotNull($lease);

        $this->storeA->release($lease);

        $remaining = $this->redisA->get($this->storeA->prefixedKey($key));
        self::assertFalse($remaining, 'Redis key must be deleted after release');
    }

    public function test_key_prefix_is_applied(): void
    {
        $key   = 'integ-prefix';
        $token = LeaseToken::generate();

        $this->storeA->acquire($key, $token, 30);

        $storedValue = $this->redisA->get('scheduler:lease:' . $key);
        self::assertSame($token->value, $storedValue);
    }

    private function connectOrSkip(): \Redis
    {
        try {
            $dsn   = (string) ($_ENV['VORTOS_CACHE_DSN'] ?? 'redis://redis:6379');
            $redis = RedisConnectionFactory::fromDsn($dsn, 1.0);
            $redis->ping();

            return $redis;
        } catch (Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }
    }

    private function cleanKeys(): void
    {
        try {
            $keys = $this->redisA->keys('scheduler:lease:integ-*');
            if (!empty($keys)) {
                $this->redisA->del(...$keys);
            }
        } catch (Throwable) {
        }
    }
}
