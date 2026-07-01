<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Lease\Driver\PostgresAdvisoryLeaseStore;
use Vortos\Scheduler\Lease\Driver\RedisLeaseStore;
use Vortos\Scheduler\Lease\Driver\SqlLeaseStore;
use Vortos\Scheduler\Lease\LeasePort;

final class LeaseDriverPass implements CompilerPassInterface
{
    public const TAG = 'vortos_scheduler.lease_driver';

    private const DRIVER_MAP = [
        'sql'               => SqlLeaseStore::class,
        'redis'             => RedisLeaseStore::class,
        'postgres-advisory' => PostgresAdvisoryLeaseStore::class,
        'in-memory'         => InMemoryLeaseStore::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        // Set by SchedulerExtension::load() from VortosSchedulerConfig — this pass runs
        // as a separate compile step afterward, so a container parameter is how the
        // resolved config value crosses that boundary. Falls back to the raw env var
        // for any container that adds this pass without loading SchedulerExtension first.
        $driverKey = $container->hasParameter('vortos_scheduler.lease_driver')
            ? (string) $container->getParameter('vortos_scheduler.lease_driver')
            : (string) ($_ENV['VORTOS_SCHEDULER_LEASE_DRIVER'] ?? 'sql');

        $driverClass = self::DRIVER_MAP[$driverKey] ?? null;

        if ($driverClass === null) {
            throw new \RuntimeException(sprintf(
                'Unknown scheduler lease driver "%s". Valid options: %s.',
                $driverKey,
                implode(', ', array_keys(self::DRIVER_MAP)),
            ));
        }

        if (!$container->hasDefinition($driverClass) && !$container->hasAlias($driverClass)) {
            throw new \RuntimeException(sprintf(
                'Scheduler lease driver "%s" (%s) is not available. '
                . 'Ensure the required extension (DBAL, ext-redis) is installed and configured.',
                $driverKey,
                $driverClass,
            ));
        }

        $container->setAlias(LeasePort::class, $driverClass)->setPublic(false);
    }
}
