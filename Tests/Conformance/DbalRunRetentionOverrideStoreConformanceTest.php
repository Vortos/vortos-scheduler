<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use Doctrine\DBAL\DriverManager;
use Vortos\Scheduler\Store\Dbal\DbalRunRetentionOverrideStore;
use Vortos\Scheduler\Store\RunRetentionOverrideStoreInterface;
use Vortos\Scheduler\Testing\RunRetentionOverrideStoreConformanceTestCase;

/**
 * Runs the full conformance test suite against an SQLite in-memory database.
 *
 * No external infrastructure required — safe to run in any environment.
 */
final class DbalRunRetentionOverrideStoreConformanceTest extends RunRetentionOverrideStoreConformanceTestCase
{
    private const TABLE = 'vortos_scheduler_run_retention_overrides';

    protected function createStore(): RunRetentionOverrideStoreInterface
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('
            CREATE TABLE ' . self::TABLE . ' (
                tenant_id      VARCHAR(255) NOT NULL,
                retention_days INTEGER      NOT NULL,
                actor_id       VARCHAR(255) NOT NULL,
                updated_at     VARCHAR(32)  NOT NULL,
                PRIMARY KEY (tenant_id)
            )
        ');

        return new DbalRunRetentionOverrideStore($conn, self::TABLE);
    }
}
