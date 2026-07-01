<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Lease\Driver\PostgresAdvisoryLeaseStore;
use Vortos\Scheduler\Lease\Exception\LeaseNotOwnedException;
use Vortos\Scheduler\Lease\Lease;
use Vortos\Scheduler\Lease\LeaseToken;

final class PostgresAdvisoryLeaseStoreConcurrencyTest extends TestCase
{
    private Connection $connA;
    private Connection $connB;
    private MutableClock $clock;
    private PostgresAdvisoryLeaseStore $storeA;

    protected function setUp(): void
    {
        $this->connA  = $this->connectOrSkip();
        $this->connB  = $this->connectOrSkip();
        $this->clock  = new MutableClock(new DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->storeA = new PostgresAdvisoryLeaseStore($this->connA, $this->clock);
    }

    protected function tearDown(): void
    {
        try {
            $this->storeA->releaseAll();
        } catch (Throwable) {
        }

        try {
            $this->connA->fetchOne('SELECT pg_advisory_unlock_all()');
            $this->connB->fetchOne('SELECT pg_advisory_unlock_all()');
        } catch (Throwable) {
        }
    }

    public function test_advisory_lock_mutual_exclusion_across_connections(): void
    {
        $key   = 'integ-advisory-mutex';
        $token = LeaseToken::generate();

        $leaseA = $this->storeA->acquire($key, $token, 30);
        self::assertNotNull($leaseA);

        // Compute the same hash used by the store and verify it is locked on conn B.
        $hash = $this->computeHash($key);
        $canB = (bool) $this->connB->fetchOne('SELECT pg_try_advisory_lock(?)', [$hash]);

        self::assertFalse($canB, 'Conn B must not acquire a lock held by conn A');
    }

    public function test_advisory_lock_released_on_store_release(): void
    {
        $key    = 'integ-advisory-release';
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();
        $storeB = new PostgresAdvisoryLeaseStore($this->connB, $this->clock);

        $leaseA = $this->storeA->acquire($key, $tokenA, 30);
        self::assertNotNull($leaseA);

        $this->storeA->release($leaseA);

        $leaseB = $storeB->acquire($key, $tokenB, 30);
        self::assertNotNull($leaseB, 'Conn B must acquire after conn A releases');

        $storeB->releaseAll();
    }

    public function test_inprocess_expiry_enforcement(): void
    {
        $key    = 'integ-advisory-expiry';
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $leaseA = $this->storeA->acquire($key, $tokenA, 5);
        self::assertNotNull($leaseA);

        // Advance the in-memory clock past the TTL.
        $this->clock->advanceSeconds(10);

        // Same store should release the expired entry and re-acquire.
        $leaseB = $this->storeA->acquire($key, $tokenB, 30);
        self::assertNotNull($leaseB, 'Should re-acquire after in-memory expiry advances');
        self::assertTrue($leaseB->isOwnedBy($tokenB));
    }

    public function test_wrong_token_throws_not_owned(): void
    {
        $key       = 'integ-advisory-token';
        $realToken = LeaseToken::generate();
        $fakeToken = LeaseToken::generate();

        $realLease = $this->storeA->acquire($key, $realToken, 30);
        self::assertNotNull($realLease);

        $fakeLease = new Lease($key, $fakeToken, $realLease->acquiredAt, $realLease->expiresAt);

        $this->expectException(LeaseNotOwnedException::class);
        $this->storeA->release($fakeLease);
    }

    private function connectOrSkip(): Connection
    {
        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['VORTOS_WRITE_DB_HOST'] ?? 'write_db',
                'port'     => (int) ($_ENV['VORTOS_WRITE_DB_PORT'] ?? 5432),
                'user'     => $_ENV['VORTOS_WRITE_DB_USER'] ?? 'postgres',
                'password' => $_ENV['VORTOS_WRITE_DB_PASSWORD'] ?? '12345',
                'dbname'   => $_ENV['VORTOS_WRITE_DB_NAME'] ?? 'squaura',
            ]);
            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (Throwable $e) {
            $this->markTestSkipped('Postgres not reachable: ' . $e->getMessage());
        }
    }

    private function computeHash(string $key): int
    {
        $raw      = hash('sha256', 'scheduler:' . $key, true);
        $unsigned = unpack('J', substr($raw, 0, 8))[1];

        if ($unsigned > PHP_INT_MAX) {
            return (int) ($unsigned - (2 ** 64));
        }

        return (int) $unsigned;
    }
}
