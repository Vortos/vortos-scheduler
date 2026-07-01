<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Throwable;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Lease\Driver\PostgresAdvisoryLeaseStore;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Testing\LeasePortConformanceTestCase;

final class PostgresAdvisoryLeaseStoreConformanceTest extends LeasePortConformanceTestCase
{
    private Connection $connection;
    private PostgresAdvisoryLeaseStore $advisoryStore;
    private MutableClock $testClock;

    protected function setUp(): void
    {
        $this->connection = $this->connectOrSkip();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Release all advisory locks held by this connection so tests are isolated.
        try {
            $this->advisoryStore->releaseAll();
        } catch (Throwable) {
        }
    }

    protected function createClock(): MutableClock
    {
        $this->testClock = new MutableClock(new DateTimeImmutable('2026-07-01T00:00:00Z'));

        return $this->testClock;
    }

    protected function createStore(): LeasePort
    {
        $this->advisoryStore = new PostgresAdvisoryLeaseStore($this->connection, $this->testClock);

        return $this->advisoryStore;
    }

    protected function supportsExplicitTtlExpiry(): bool
    {
        // Advisory store tracks expiry in-memory using the injected MutableClock.
        return true;
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
}
