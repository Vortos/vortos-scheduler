<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use DateTimeImmutable;
use Throwable;
use Vortos\Cache\Adapter\RedisConnectionFactory;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Lease\Driver\RedisLeaseStore;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Testing\LeasePortConformanceTestCase;

final class RedisLeaseStoreConformanceTest extends LeasePortConformanceTestCase
{
    private \Redis $redis;

    protected function setUp(): void
    {
        $this->redis = $this->redisOrSkip();
        $this->cleanTckKeys();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanTckKeys();
    }

    protected function createClock(): MutableClock
    {
        return new MutableClock(new DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    protected function createStore(): LeasePort
    {
        return new RedisLeaseStore($this->redis, $this->createClock());
    }

    protected function supportsExplicitTtlExpiry(): bool
    {
        // Redis enforces TTL via PEXPIRE (real wall-clock time), not MutableClock.
        return false;
    }

    private function redisOrSkip(): \Redis
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

    private function cleanTckKeys(): void
    {
        try {
            $keys = $this->redis->keys('scheduler:lease:tck-*');
            if (!empty($keys)) {
                $this->redis->del(...$keys);
            }
        } catch (Throwable) {
        }
    }
}
