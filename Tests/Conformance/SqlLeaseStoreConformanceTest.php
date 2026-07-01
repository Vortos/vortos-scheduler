<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Throwable;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Lease\Driver\SqlLeaseStore;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Testing\LeasePortConformanceTestCase;

final class SqlLeaseStoreConformanceTest extends LeasePortConformanceTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        $this->ensureTable();
        $this->cleanTckRows();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->cleanTckRows();
    }

    protected function createClock(): MutableClock
    {
        // Use real current time so lease expires_at is in the future relative to the real DB NOW().
        // A fixed past timestamp causes the DB to treat every lease as already expired.
        return new MutableClock(new DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    protected function createStore(): LeasePort
    {
        return new SqlLeaseStore($this->connection, $this->createClock(), 'vortos_scheduler_leases');
    }

    protected function supportsExplicitTtlExpiry(): bool
    {
        // SQL driver enforces TTL via the database's NOW(), not the injected MutableClock.
        // Fast-forwarding MutableClock has no effect on DB-side expiry checks.
        return false;
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

    private function ensureTable(): void
    {
        $this->connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS vortos_scheduler_leases (
                lease_key   VARCHAR(255) NOT NULL,
                owner_token VARCHAR(64)  NOT NULL,
                acquired_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                expires_at  TIMESTAMPTZ  NOT NULL,
                renewed_at  TIMESTAMPTZ,
                CONSTRAINT pk_vortos_scheduler_leases PRIMARY KEY (lease_key)
            )"
        );
    }

    private function cleanTckRows(): void
    {
        try {
            $this->connection->executeStatement(
                "DELETE FROM vortos_scheduler_leases WHERE lease_key LIKE 'tck-%'"
            );
        } catch (Throwable) {
        }
    }
}
