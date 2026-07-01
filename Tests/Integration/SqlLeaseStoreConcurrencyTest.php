<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Lease\Driver\SqlLeaseStore;
use Vortos\Scheduler\Lease\Exception\LeaseNotOwnedException;
use Vortos\Scheduler\Lease\Lease;
use Vortos\Scheduler\Lease\LeaseToken;

final class SqlLeaseStoreConcurrencyTest extends TestCase
{
    private const TABLE = 'vortos_scheduler_leases';

    private Connection $connA;
    private Connection $connB;
    private SqlLeaseStore $storeA;
    private SqlLeaseStore $storeB;

    protected function setUp(): void
    {
        $this->connA  = $this->connectOrSkip();
        $this->connB  = $this->connectOrSkip();
        $this->storeA = new SqlLeaseStore($this->connA, new MutableClock(new DateTimeImmutable()), self::TABLE);
        $this->storeB = new SqlLeaseStore($this->connB, new MutableClock(new DateTimeImmutable()), self::TABLE);

        $this->ensureTable($this->connA);
        $this->cleanRows();
    }

    protected function tearDown(): void
    {
        $this->cleanRows();
    }

    public function test_two_nodes_only_one_acquires_same_key(): void
    {
        $key    = 'integ-mutex';
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $leaseA = $this->storeA->acquire($key, $tokenA, 30);
        self::assertNotNull($leaseA);

        $leaseB = $this->storeB->acquire($key, $tokenB, 30);
        self::assertNull($leaseB);
    }

    public function test_node_b_acquires_after_node_a_releases(): void
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

    public function test_node_b_cannot_renew_node_a_lease(): void
    {
        $key       = 'integ-renew-other';
        $tokenA    = LeaseToken::generate();
        $fakeToken = LeaseToken::generate();

        $leaseA    = $this->storeA->acquire($key, $tokenA, 30);
        self::assertNotNull($leaseA);

        $fakeLease = new Lease($key, $fakeToken, $leaseA->acquiredAt, $leaseA->expiresAt);

        $this->expectException(LeaseNotOwnedException::class);
        $this->storeB->renew($fakeLease, 30);
    }

    public function test_node_b_cannot_release_node_a_lease(): void
    {
        $key       = 'integ-release-other';
        $tokenA    = LeaseToken::generate();
        $fakeToken = LeaseToken::generate();

        $leaseA    = $this->storeA->acquire($key, $tokenA, 30);
        self::assertNotNull($leaseA);

        $fakeLease = new Lease($key, $fakeToken, $leaseA->acquiredAt, $leaseA->expiresAt);

        $this->expectException(LeaseNotOwnedException::class);
        $this->storeB->release($fakeLease);
    }

    public function test_expired_lease_becomes_available_after_real_ttl(): void
    {
        $key    = 'integ-expiry';
        $tokenA = LeaseToken::generate();
        $tokenB = LeaseToken::generate();

        $leaseA = $this->storeA->acquire($key, $tokenA, 1);
        self::assertNotNull($leaseA);

        sleep(2);

        $leaseB = $this->storeB->acquire($key, $tokenB, 30);
        self::assertNotNull($leaseB, 'Lease should be available after 1s TTL + 1s sleep');
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

    private function ensureTable(Connection $connection): void
    {
        $connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                lease_key   VARCHAR(255) NOT NULL,
                owner_token VARCHAR(64)  NOT NULL,
                acquired_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                expires_at  TIMESTAMPTZ  NOT NULL,
                renewed_at  TIMESTAMPTZ,
                CONSTRAINT pk_" . self::TABLE . " PRIMARY KEY (lease_key)
            )"
        );
    }

    private function cleanRows(): void
    {
        try {
            $this->connA->executeStatement(
                "DELETE FROM " . self::TABLE . " WHERE lease_key LIKE 'integ-%'"
            );
        } catch (Throwable) {
        }
    }
}
