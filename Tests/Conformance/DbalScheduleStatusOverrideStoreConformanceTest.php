<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Conformance;

use Doctrine\DBAL\DriverManager;
use Vortos\Scheduler\Store\Dbal\DbalScheduleStatusOverrideStore;
use Vortos\Scheduler\Store\ScheduleStatusOverrideStoreInterface;
use Vortos\Scheduler\Testing\ScheduleStatusOverrideStoreConformanceTestCase;

/**
 * Runs the full conformance test suite against an SQLite in-memory database.
 *
 * No external infrastructure required — safe to run in any environment.
 */
final class DbalScheduleStatusOverrideStoreConformanceTest extends ScheduleStatusOverrideStoreConformanceTestCase
{
    private const TABLE = 'vortos_scheduler_static_overrides';

    protected function createStore(): ScheduleStatusOverrideStoreInterface
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement('
            CREATE TABLE ' . self::TABLE . ' (
                schedule_id VARCHAR(36)  NOT NULL,
                status      VARCHAR(20)  NOT NULL,
                actor_id    VARCHAR(255) NOT NULL,
                reason      TEXT         NULL,
                updated_at  VARCHAR(32)  NOT NULL,
                PRIMARY KEY (schedule_id)
            )
        ');

        return new DbalScheduleStatusOverrideStore($conn, self::TABLE);
    }
}
