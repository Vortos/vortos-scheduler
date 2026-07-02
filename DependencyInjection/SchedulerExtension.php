<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Cache\Adapter\RedisConnectionFactory;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;
use Vortos\Scheduler\Audit\Dbal\DbalSchedulerAuditCheckpointRepository;
use Vortos\Scheduler\Audit\Dbal\DbalSchedulerAuditRepository;
use Vortos\Scheduler\Audit\InMemorySchedulerAuditCheckpointRepository;
use Vortos\Scheduler\Audit\SchedulerAuditCheckpointProjector;
use Vortos\Scheduler\Audit\SchedulerAuditCheckpointRepositoryInterface;
use Vortos\Scheduler\Audit\SchedulerAuditProjector;
use Vortos\Scheduler\Audit\SchedulerAuditRepositoryInterface;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Clock\SystemClock;
use Vortos\Scheduler\Command\PruneSchedulerRunsCommand;
use Vortos\Scheduler\Console\RetentionOverrideRemoveCommand;
use Vortos\Scheduler\Console\RetentionOverrideSetCommand;
use Vortos\Scheduler\Console\SchedulerRunCommand;
use Vortos\Scheduler\DependencyInjection\Compiler\LeaseDriverPass;
use Vortos\Scheduler\Engine\CircuitBreaker\DispatchCircuitBreaker;
use Vortos\Scheduler\Engine\DueScan;
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\Outbox\DbalSchedulerEnqueuer;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Engine\SchedulerEnqueuerPort;
use Vortos\Scheduler\Engine\SlotCalculator;
use Vortos\Scheduler\Lease\Driver\InMemoryLeaseStore;
use Vortos\Scheduler\Lease\Driver\PostgresAdvisoryLeaseStore;
use Vortos\Scheduler\Lease\Driver\RedisLeaseStore;
use Vortos\Scheduler\Lease\Driver\SqlLeaseStore;
use Vortos\Scheduler\Lease\LeasePort;
use Vortos\Scheduler\Observability\CardinalityGuardedSchedulerMetrics;
use Vortos\Scheduler\Observability\DeadManDetector;
use Vortos\Scheduler\Observability\SchedulerMetricDefinitions;
use Vortos\Scheduler\Observability\SchedulerMetrics;
use Vortos\Scheduler\Observability\SchedulerMetricsPort;
use Vortos\Scheduler\Observability\SchedulerTracer;
use Vortos\Scheduler\Store\Dbal\DbalRunRetentionOverrideStore;
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Store\Dbal\DbalScheduleStore;
use Vortos\Scheduler\Store\Dbal\ScheduleSerializer;
use Vortos\Scheduler\Store\RunRetentionOverrideStoreInterface;
use Vortos\Scheduler\DependencyInjection\Compiler\StaticSchedulePass;
use Vortos\Scheduler\Registry\CachingScheduleResolver;
use Vortos\Scheduler\Registry\PruneSchedulerRunsSchedule;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Retention\FireQueuePruner;
use Vortos\Scheduler\Retention\RunRetentionSweeper;
use Vortos\Scheduler\Security\Approval\Dbal\DbalFourEyesApprovalStore;
use Vortos\Scheduler\Security\FourEyesGate;
use Vortos\Scheduler\Security\NullSchedulePolicy;
use Vortos\Scheduler\Security\SchedulePolicy;
use Vortos\Scheduler\Security\SchedulePolicyInterface;
use Vortos\Scheduler\Security\SchedulerPermissionCatalog;
use Vortos\Scheduler\Security\SchedulerResourcePolicy;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Store\ScheduleStoreInterface;
use Vortos\Tracing\Contract\TracingInterface;

use Vortos\Scheduler\Console\ScheduleApproveCommand;
use Vortos\Scheduler\Console\ScheduleDoctorCommand;
use Vortos\Scheduler\Console\ScheduleListCommand;
use Vortos\Scheduler\Console\SchedulePauseCommand;
use Vortos\Scheduler\Console\SchedulePruneCommand;
use Vortos\Scheduler\Console\ScheduleResumeCommand;
use Vortos\Scheduler\Console\ScheduleRunNowCommand;
use Vortos\Scheduler\Doctor\SchedulerDoctor;
use Vortos\Scheduler\Doctor\SchedulerPreflightCheck;
use Vortos\Scheduler\Engine\FireDispatcherPort;
use Vortos\Scheduler\Service\ScheduleService;
use Vortos\Scheduler\Store\Dbal\DbalScheduleStatusOverrideStore;
use Vortos\Scheduler\Store\ScheduleStatusOverrideStoreInterface;

final class SchedulerExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_scheduler';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->loadConfig($container);

        // LeaseDriverPass runs as a separate compiler pass, after Extension::load()
        // returns — a container parameter is how a resolved config value crosses
        // that boundary, since the pass has no other way to see $config.
        $container->setParameter('vortos_scheduler.lease_driver', $config['lease_driver']);

        $this->registerClock($container);
        $this->registerLeaseDrivers($container, $config);
        $this->registerStores($container, $config);
        $this->registerEngine($container, $config);
        $this->registerResolver($container, $config);
        $this->registerSecurity($container, $config);
        $this->registerAudit($container, $config);
        $this->registerMetrics($container, $config);
        $this->registerObservability($container, $config);
        $this->registerDaemon($container, $config);
        $this->registerOverrideStore($container);
        $this->registerRetention($container, $config);
        $this->registerConsumer($container, $config);
        $this->registerService($container);
        $this->registerDoctor($container, $config);
        $this->registerConsoleCommands($container);
    }

    /**
     * Loads config/scheduler.php then config/{env}/scheduler.php (env overrides base) —
     * same convention as CacheExtension/AuthExtension/CqrsExtension/... throughout this
     * framework: kernel.project_dir and kernel.env are hard requirements, not optional.
     */
    private function loadConfig(ContainerBuilder $container): array
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $env        = (string) $container->getParameter('kernel.env');

        $config = new VortosSchedulerConfig();

        $base = $projectDir . '/config/scheduler.php';
        if (file_exists($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/scheduler.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        return $this->processConfiguration(new Configuration(), [$config->toArray()]);
    }

    private function registerResolver(ContainerBuilder $container, array $config): void
    {
        // Autoconfigure: any StaticScheduleDefinition impl automatically gets the static_schedule tag.
        $container->registerForAutoconfiguration(StaticScheduleDefinition::class)
            ->addTag(StaticSchedulePass::TAG);

        // Register the registry with an empty class list — StaticSchedulePass fills it at compile time.
        if (!$container->hasDefinition(StaticScheduleRegistry::class)) {
            $container->register(StaticScheduleRegistry::class, StaticScheduleRegistry::class)
                ->setArgument('$definitionClasses', [])
                ->setPublic(false);
        }

        // ScheduleResolver requires the DBAL store. Skip if DBAL is not available.
        if (!$container->hasDefinition(DbalScheduleStore::class)
            && !$container->hasAlias(ScheduleStoreInterface::class)) {
            return;
        }

        $container->register(ScheduleResolver::class, ScheduleResolver::class)
            ->setArgument('$registry', new Reference(StaticScheduleRegistry::class))
            ->setArgument('$store', new Reference(ScheduleStoreInterface::class))
            ->setArgument('$overrideStore', new Reference(ScheduleStatusOverrideStoreInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        // E4: in-process TTL cache for the resolver — reduces store round-trips in CLI/admin paths.
        // The daemon still uses the raw ScheduleResolver directly for zero-overhead hot path.
        $container->register(CachingScheduleResolver::class, CachingScheduleResolver::class)
            ->setArgument('$inner',  new Reference(ScheduleResolver::class))
            ->setArgument('$clock',  new Reference(ClockPort::class))
            ->setArgument('$ttlSec', $config['resolver_cache_ttl_sec'])
            ->setPublic(false);
    }

    private function registerClock(ContainerBuilder $container): void
    {
        $container->register(SystemClock::class, SystemClock::class)
            ->setPublic(false);

        $container->setAlias(ClockPort::class, SystemClock::class);
        $container->setAlias(ClockInterface::class, SystemClock::class);
    }

    private function registerStores(ContainerBuilder $container, array $config): void
    {
        if (!class_exists(Connection::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(ScheduleSerializer::class, ScheduleSerializer::class)
            ->setPublic(false);

        $container->register(DbalScheduleStore::class, DbalScheduleStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$serializer', new Reference(ScheduleSerializer::class))
            ->setArgument('$table', $prefix . 'scheduler_schedules')
            ->setPublic(false);

        $container->register(DbalScheduleRunStore::class, DbalScheduleRunStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'scheduler_runs')
            ->setArgument('$pruneBatchSize', $config['prune_batch_size'])
            ->setArgument('$pruneMaxDurationSec', $config['prune_max_duration_sec'])
            ->setPublic(false);

        $container->register(DbalRunRetentionOverrideStore::class, DbalRunRetentionOverrideStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'scheduler_run_retention_overrides')
            ->setPublic(false);

        $container->setAlias(ScheduleStoreInterface::class, DbalScheduleStore::class);
        $container->setAlias(ScheduleRunStoreInterface::class, DbalScheduleRunStore::class);
        $container->setAlias(RunRetentionOverrideStoreInterface::class, DbalRunRetentionOverrideStore::class);
    }

    private function registerEngine(ContainerBuilder $container, array $config): void
    {
        // Pure engine components — no infrastructure dependencies
        $container->register(SlotCalculator::class, SlotCalculator::class)
            ->setPublic(false);

        $container->register(MisfireResolver::class, MisfireResolver::class)
            ->setArgument('$slotCalculator', new Reference(SlotCalculator::class))
            ->setPublic(false);

        $container->register(DueScan::class, DueScan::class)
            ->setArgument('$misfireResolver', new Reference(MisfireResolver::class))
            ->setArgument('$maxCatchupAgeSec', $config['max_catchup_age_sec'])
            ->setPublic(false);

        if (!class_exists(Connection::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(DbalSchedulerEnqueuer::class, DbalSchedulerEnqueuer::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'scheduler_fire_queue')
            ->setPublic(false);

        $container->setAlias(SchedulerEnqueuerPort::class, DbalSchedulerEnqueuer::class);

        $container->register(FireDispatcher::class, FireDispatcher::class)
            ->setArgument('$runStore',         new Reference(ScheduleRunStoreInterface::class))
            ->setArgument('$enqueuer',         new Reference(SchedulerEnqueuerPort::class))
            ->setArgument('$connection',       new Reference(Connection::class))
            ->setArgument('$clock',            new Reference(ClockInterface::class))
            ->setArgument('$assumedDoneTtlSec', $config['assumed_done_ttl_sec'])
            ->setPublic(false);

        // E3: circuit-breaker wraps FireDispatcher; opens after N consecutive backend failures.
        $container->register(DispatchCircuitBreaker::class, DispatchCircuitBreaker::class)
            ->setArgument('$inner',             new Reference(FireDispatcher::class))
            ->setArgument('$clock',             new Reference(ClockPort::class))
            ->setArgument('$failureThreshold',  $config['circuit_breaker_failure_threshold'])
            ->setArgument('$recoveryWindowSec', $config['circuit_breaker_recovery_window_sec'])
            ->setPublic(false);

        $container->setAlias(FireDispatcherPort::class, DispatchCircuitBreaker::class);
    }

    private function registerSecurity(ContainerBuilder $container, array $config): void
    {
        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        // 4-eyes approval store (DBAL, optional — requires DBAL)
        if (class_exists(Connection::class) && $container->hasDefinition(DbalFourEyesApprovalStore::class) === false) {
            $container->register(DbalFourEyesApprovalStore::class, DbalFourEyesApprovalStore::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$table', $prefix . 'scheduler_approvals')
                ->setPublic(false);
        }

        // 4-eyes gate
        if ($container->hasDefinition(DbalFourEyesApprovalStore::class)) {
            $container->register(FourEyesGate::class, FourEyesGate::class)
                ->setArgument('$store',          new Reference(DbalFourEyesApprovalStore::class))
                ->setArgument('$clock',          new Reference(ClockInterface::class))
                ->setArgument('$approvalTtlSec', $config['approval_ttl_sec'])
                ->setPublic(false);
        }

        // RBAC policy: real implementation when vortos-authorization is installed,
        // NullSchedulePolicy otherwise.
        $policyEngineClass = 'Vortos\Authorization\Engine\PolicyEngine';
        if (class_exists($policyEngineClass) && $container->hasDefinition($policyEngineClass)) {
            // Register resource policy and permission catalog (tagged for auto-discovery)
            $container->register(SchedulerResourcePolicy::class, SchedulerResourcePolicy::class)
                ->addTag('vortos.policy', ['resource' => 'scheduler'])
                ->setPublic(false);

            $container->register(SchedulerPermissionCatalog::class, SchedulerPermissionCatalog::class)
                ->addTag('vortos.permission_catalog', ['resource' => 'scheduler'])
                ->setPublic(false);

            $container->register(SchedulePolicy::class, SchedulePolicy::class)
                ->setArgument('$policyEngine', new Reference($policyEngineClass))
                ->setPublic(false);

            $container->setAlias(SchedulePolicyInterface::class, SchedulePolicy::class);
        } else {
            $container->register(NullSchedulePolicy::class, NullSchedulePolicy::class)
                ->setArgument('$logger', new Reference(LoggerInterface::class))
                ->setPublic(false);

            $container->setAlias(SchedulePolicyInterface::class, NullSchedulePolicy::class);
        }
    }

    private function registerAudit(ContainerBuilder $container, array $config): void
    {
        if (!class_exists(Connection::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(DbalSchedulerAuditRepository::class, DbalSchedulerAuditRepository::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'scheduler_audit_log')
            ->setPublic(false);

        $container->setAlias(SchedulerAuditRepositoryInterface::class, DbalSchedulerAuditRepository::class);

        $hmacKey = $config['audit_hmac_key'];
        $env     = (string) ($_ENV['APP_ENV'] ?? 'production');

        // E5: per-epoch HMAC checkpoints for fast O(n/epochSize) chain verification.
        // Use DBAL if available, in-memory otherwise (tests / no-DB bootstrap).
        $container->register(DbalSchedulerAuditCheckpointRepository::class, DbalSchedulerAuditCheckpointRepository::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'scheduler_audit_checkpoints')
            ->setPublic(false);

        $container->register(InMemorySchedulerAuditCheckpointRepository::class, InMemorySchedulerAuditCheckpointRepository::class)
            ->setPublic(false);

        $container->setAlias(
            SchedulerAuditCheckpointRepositoryInterface::class,
            DbalSchedulerAuditCheckpointRepository::class,
        );

        if ($hmacKey !== '') {
            $container->register(SchedulerAuditCheckpointProjector::class, SchedulerAuditCheckpointProjector::class)
                ->setArgument('$repository', new Reference(SchedulerAuditCheckpointRepositoryInterface::class))
                ->setArgument('$hmacKey',    $hmacKey)
                ->setArgument('$epochSize',  $config['audit_epoch_size'])
                ->setPublic(false);

            $container->register(SchedulerAuditProjector::class, SchedulerAuditProjector::class)
                ->setArgument('$repository',            new Reference(SchedulerAuditRepositoryInterface::class))
                ->setArgument('$hmacKey',               $hmacKey)
                ->setArgument('$env',                   $env)
                ->setArgument('$logger',                new Reference(LoggerInterface::class))
                ->setArgument('$checkpointProjector',   new Reference(SchedulerAuditCheckpointProjector::class))
                ->setPublic(false);
        }
    }

    private function registerMetrics(ContainerBuilder $container, array $config): void
    {
        // Register metric definitions so MetricDefinitionsCompilerPass picks them up.
        if (interface_exists(MetricDefinitionProviderInterface::class)) {
            $container->register(SchedulerMetricDefinitions::class, SchedulerMetricDefinitions::class)
                ->addTag(MetricDefinitionProviderInterface::TAG)
                ->setPublic(false);
        }

        // Wire the SchedulerMetrics service — null MetricsInterface when not installed.
        $metricsRef = interface_exists(MetricsInterface::class) && $container->has(MetricsInterface::class)
            ? new Reference(MetricsInterface::class)
            : null;

        $container->register(SchedulerMetrics::class, SchedulerMetrics::class)
            ->setArgument('$metrics', $metricsRef)
            ->setPublic(false);

        // E1: cardinality-guarded wrapper keeps Prometheus label space bounded.
        $container->register(CardinalityGuardedSchedulerMetrics::class, CardinalityGuardedSchedulerMetrics::class)
            ->setArgument('$inner', new Reference(SchedulerMetrics::class))
            ->setArgument('$metrics', $metricsRef)
            ->setArgument('$maxDistinctSchedules', $config['metrics_max_cardinality'])
            ->setPublic(false);

        $container->setAlias(SchedulerMetricsPort::class, CardinalityGuardedSchedulerMetrics::class);
    }

    private function registerObservability(ContainerBuilder $container, array $config): void
    {
        // SchedulerTracer — wraps framework TracingInterface (null = no-op)
        $tracerRef = interface_exists(TracingInterface::class) && $container->has(TracingInterface::class)
            ? new Reference(TracingInterface::class)
            : null;

        $container->register(SchedulerTracer::class, SchedulerTracer::class)
            ->setArgument('$tracer', $tracerRef)
            ->setPublic(false);

        // Inject tracer into FireDispatcher if it is registered
        if ($container->hasDefinition(FireDispatcher::class)) {
            $container->getDefinition(FireDispatcher::class)
                ->setArgument('$tracer', new Reference(SchedulerTracer::class));
        }

        // DeadManDetector — requires AlertDispatcherInterface from vortos-alerts
        $alertsClass = 'Vortos\Alerts\AlertDispatcherInterface';
        if (class_exists($alertsClass) && $container->has($alertsClass) && $container->hasDefinition(DbalScheduleRunStore::class)) {
            $container->register(DeadManDetector::class, DeadManDetector::class)
                ->setArgument('$runStore',        new Reference(ScheduleRunStoreInterface::class))
                ->setArgument('$dispatcher',      new Reference($alertsClass))
                ->setArgument('$clock',           new Reference(ClockPort::class))
                ->setArgument('$env',             (string) ($_ENV['APP_ENV'] ?? 'production'))
                ->setArgument('$defaultToleranceSec', $config['dead_man_tolerance_sec'])
                ->setArgument('$logger',          new Reference(LoggerInterface::class))
                ->setPublic(false);
        }
    }

    private function registerDaemon(ContainerBuilder $container, array $config): void
    {
        if (!$container->hasDefinition(FireDispatcher::class)) {
            return; // DBAL not available — daemon requires store + dispatcher
        }

        if (!$container->hasDefinition(ScheduleResolver::class)) {
            return; // Resolver not registered (DBAL unavailable in registerResolver)
        }

        $container->register(SchedulerDaemon::class, SchedulerDaemon::class)
            ->setArgument('$leasePort',               new Reference(LeasePort::class))
            ->setArgument('$scheduleResolver',        new Reference(ScheduleResolver::class))
            ->setArgument('$runStore',                new Reference(ScheduleRunStoreInterface::class))
            ->setArgument('$dueScan',                 new Reference(DueScan::class))
            ->setArgument('$fireDispatcher',          new Reference(FireDispatcher::class))
            ->setArgument('$clock',                   new Reference(ClockPort::class))
            ->setArgument('$logger',                  new Reference(LoggerInterface::class))
            ->setArgument('$shardCount',              $config['shard_count'])
            ->setArgument('$leaseTtlSec',             $config['lease_ttl_sec'])
            ->setArgument('$maxIdleSec',              $config['max_idle_sec'])
            ->setArgument('$tenantMaxConcurrentFires', $config['tenant_max_concurrent_fires'])
            ->setArgument('$metrics', new Reference(SchedulerMetricsPort::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$audit',   new Reference(SchedulerAuditProjector::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$deadMan', new Reference(DeadManDetector::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        $container->register(SchedulerRunCommand::class, SchedulerRunCommand::class)
            ->setArgument('$daemon', new Reference(SchedulerDaemon::class))
            ->addTag('console.command')
            ->setPublic(false);

        if (\class_exists(\Vortos\Docker\Worker\WorkerProcessDefinition::class)) {
            $container->register('vortos_scheduler.worker.daemon', \Vortos\Docker\Worker\WorkerProcessDefinition::class)
                ->setArguments([
                    'scheduler-daemon',
                    'php /var/www/html/bin/console scheduler:run',
                    'Vortos Scheduler: leader-elected distributed daemon.',
                    true,  // autostart
                    true,  // autorestart
                    3,     // startsecs
                    35,    // stopwaitsecs (must be >= drainDeadline)
                    30,    // drainDeadline
                ])
                ->addTag('vortos.worker')
                ->setPublic(false);
        }
    }

    private function registerLeaseDrivers(ContainerBuilder $container, array $config): void
    {
        $container->register(InMemoryLeaseStore::class, InMemoryLeaseStore::class)
            ->setArgument('$clock', new Reference(ClockPort::class))
            ->addTag(LeaseDriverPass::TAG)
            ->setPublic(false);

        if (class_exists(Connection::class)) {
            $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
                ? (string) $container->getParameter('vortos.db.framework_table_prefix')
                : 'vortos_';

            $container->register(SqlLeaseStore::class, SqlLeaseStore::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$clock', new Reference(ClockPort::class))
                ->setArgument('$table', $prefix . 'scheduler_leases')
                ->addTag(LeaseDriverPass::TAG)
                ->setPublic(false);

            $container->register(PostgresAdvisoryLeaseStore::class, PostgresAdvisoryLeaseStore::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$clock', new Reference(ClockPort::class))
                ->addTag(LeaseDriverPass::TAG)
                ->setPublic(false);
        }

        if (extension_loaded('redis')) {
            $container->register('vortos_scheduler.redis', \Redis::class)
                ->setFactory([RedisConnectionFactory::class, 'fromDsn'])
                ->setArgument(0, $config['redis_dsn'])
                ->setPublic(false);

            $container->register(RedisLeaseStore::class, RedisLeaseStore::class)
                ->setArgument('$redis', new Reference('vortos_scheduler.redis'))
                ->setArgument('$clock', new Reference(ClockPort::class))
                ->addTag(LeaseDriverPass::TAG)
                ->setPublic(false);
        }
    }
    private function registerOverrideStore(ContainerBuilder $container): void
    {
        if (!class_exists(Connection::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(DbalScheduleStatusOverrideStore::class, DbalScheduleStatusOverrideStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'scheduler_static_overrides')
            ->setPublic(false);

        $container->setAlias(ScheduleStatusOverrideStoreInterface::class, DbalScheduleStatusOverrideStore::class);
    }

    /**
     * Auto-prune (retention) wiring. Registers RunRetentionSweeper unconditionally
     * (used by both the automatic schedule below and the manual scheduler:prune
     * CLI's default mode) and conditionally registers PruneSchedulerRunsSchedule +
     * its CQRS handler — but only when the resolved retention is non-zero, so a
     * globally-disabled install never registers a schedule that fires and no-ops.
     */
    private function registerRetention(ContainerBuilder $container, array $config): void
    {
        if (!class_exists(Connection::class) || !$container->hasDefinition(FireDispatcher::class)) {
            return; // DBAL/engine not available
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        // Terminal fire-queue rows accumulate forever otherwise (S12 marks them
        // dispatched/failed instead of deleting). Pruned as a side-step of the
        // daily run sweep — a no-op when fire_queue_retention_days is 0.
        if ($config['fire_queue_retention_days'] > 0) {
            $container->register(FireQueuePruner::class, FireQueuePruner::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$clock', new Reference(ClockPort::class))
                ->setArgument('$retentionDays', $config['fire_queue_retention_days'])
                ->setArgument('$table', $prefix . 'scheduler_fire_queue')
                ->setArgument('$batchSize', $config['prune_batch_size'])
                ->setArgument('$maxDurationSec', $config['prune_max_duration_sec'])
                ->setArgument('$logger', new Reference(LoggerInterface::class))
                ->setArgument('$metrics', new Reference(SchedulerMetricsPort::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->setPublic(false);
        }

        $container->register(RunRetentionSweeper::class, RunRetentionSweeper::class)
            ->setArgument('$runStore', new Reference(ScheduleRunStoreInterface::class))
            ->setArgument('$overrideStore', new Reference(RunRetentionOverrideStoreInterface::class))
            ->setArgument('$clock', new Reference(ClockPort::class))
            ->setArgument('$tracer', new Reference(SchedulerTracer::class))
            ->setArgument('$globalRetentionDays', $config['run_retention_days'])
            ->setArgument('$audit', new Reference(SchedulerAuditProjector::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$metrics', new Reference(SchedulerMetricsPort::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$fireQueuePruner', new Reference(FireQueuePruner::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        if ($config['run_retention_days'] <= 0) {
            return; // Disabled — no schedule registered, no daily no-op fire.
        }

        $container->register(PruneSchedulerRunsSchedule::class, PruneSchedulerRunsSchedule::class)
            ->addTag(StaticSchedulePass::TAG)
            ->setPublic(false);

        // PruneSchedulerRunsCommand is never fetched via the container (CommandHydrator
        // instantiates it by reflection, not $container->get()) — but it must still be
        // registered as a definition so SchedulableCommandPass::buildAllowlist()'s
        // #[SchedulableCommand] attribute scan (which only inspects already-registered
        // definitions, not arbitrary classes) discovers it. Without this, any app that
        // also registers at least one other #[SchedulableCommand] class would make the
        // allowlist non-empty and crossCheckStaticSchedules() would reject
        // PruneSchedulerRunsSchedule as referencing an unlisted command.
        $container->register(PruneSchedulerRunsCommand::class, PruneSchedulerRunsCommand::class)
            ->setPublic(false);

        // The auto-prune schedule is live, so its CQRS command handler must be wired — but only
        // when the CommandBus is installed. That gate is a cross-package alias check, which is
        // unreliable during load() under MergeExtensionConfigurationPass isolation (it reads false
        // even when the bus is present). We flag the schedule as active here and let
        // {@see ConsumerRegistrationPass} register the handler at build time, where the alias is
        // visible. Without this seam the handler was silently skipped and the daily prune fire sat
        // undispatchable in the queue.
        $container->setParameter('vortos_scheduler.auto_prune_active', true);
    }

    /**
     * S12: fire-queue consumer. Without a consumer, scheduled commands are recorded as
     * "dispatched" in the ledger but never actually execute — see
     * SCHEDULER_AUTO_PRUNE_IMPL_PLAN.md "Prerequisite 2".
     *
     * The consumer depends on the CQRS CommandBus, whose alias is owned by another package and is
     * NOT visible during load() under MergeExtensionConfigurationPass isolation. A load()-time
     * hasAlias() check therefore silently skipped the whole consumer even when the bus was
     * installed. The actual wiring now lives in {@see ConsumerRegistrationPass}, which runs at
     * build time where the alias is visible. Here we only export the config the pass cannot
     * otherwise see across the load()/compile() boundary (a container parameter is the standard
     * seam — same as vortos_scheduler.lease_driver above).
     */
    private function registerConsumer(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('vortos_scheduler.consume_batch_size', $config['consume_batch_size']);
        $container->setParameter('vortos_scheduler.consume_poll_interval_sec', $config['consume_poll_interval_sec']);
    }

    private function registerService(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ScheduleResolver::class)) {
            return;
        }

        if (!$container->hasAlias(ScheduleStatusOverrideStoreInterface::class)
            && !$container->hasDefinition(ScheduleStatusOverrideStoreInterface::class)) {
            return;
        }

        // Public: this is the package's app-facing facade. Every console command and every
        // downstream driver (FireDispatcher, CommandSpecValidator, ScheduleResolver, ...) hangs
        // off this constructor. If it stays private, RemoveUnusedDefinitionsPass prunes the whole
        // dispatch chain in any container that doesn't also wire Symfony's AddConsoleCommandPass —
        // which real apps always do, but a minimal test/embedding container may not.
        $container->register(ScheduleService::class, ScheduleService::class)
            ->setArgument('$staticRegistry', new Reference(StaticScheduleRegistry::class))
            ->setArgument('$dynamicStore', new Reference(ScheduleStoreInterface::class))
            ->setArgument('$overrideStore', new Reference(ScheduleStatusOverrideStoreInterface::class))
            ->setArgument('$policy', new Reference(SchedulePolicyInterface::class))
            ->setArgument('$clock', new Reference(ClockPort::class))
            ->setArgument('$fireDispatcher', new Reference(FireDispatcherPort::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$fourEyesGate', new Reference(FourEyesGate::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$audit', new Reference(SchedulerAuditProjector::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$retentionOverrideStore', new Reference(RunRetentionOverrideStoreInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(true);
    }

    private function registerDoctor(ContainerBuilder $container, array $config): void
    {
        if (!$container->hasDefinition(ScheduleResolver::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        if (!class_exists(Connection::class)) {
            return;
        }

        $container->register(SchedulerDoctor::class, SchedulerDoctor::class)
            ->setArgument('$resolver', new Reference(ScheduleResolver::class))
            ->setArgument('$dynamicStore', new Reference(ScheduleStoreInterface::class))
            ->setArgument('$leasePort', new Reference(LeasePort::class))
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$clock', new Reference(ClockPort::class))
            ->setArgument('$validator', new Reference(\Vortos\Scheduler\Security\CommandSpecValidator::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$approvalStore', new Reference(\Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$tablePrefix', $prefix)
            ->setArgument('$shardCount', $config['shard_count'])
            ->setArgument('$maxCatchupAgeSec', $config['max_catchup_age_sec'])
            ->setArgument('$runRetentionDays', $config['run_retention_days'])
            ->setArgument('$retentionOverrideStore', new Reference(RunRetentionOverrideStoreInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            // Default false: the fire-queue consumer is wired at build time by
            // ConsumerRegistrationPass (the CommandBus alias it gates on is not visible during
            // load()), which flips this argument to true when it actually registers the consumer.
            ->setArgument('$fireQueueConsumerInstalled', false)
            ->setArgument('$consumeStallThresholdSec', $config['consume_stall_threshold_sec'])
            ->setPublic(false);

        // D: deploy:doctor gate — only registered when vortos-deploy is installed. The
        // interface_exists() guard keeps the scheduler fully decoupled from vortos-deploy when
        // it is absent (autoloader-based, order-free). When deploy IS present we let its
        // registerForAutoconfiguration(PreflightCheckInterface) apply the correct collection
        // tag via setAutoconfigured(true) — hand-tagging previously used the wrong tag name
        // ('vortos.preflight_check' vs deploy's 'vortos.deploy.preflight_check'), so the gate
        // was registered but never collected by DeployDoctor.
        if (\interface_exists(\Vortos\Deploy\Preflight\PreflightCheckInterface::class)) {
            $container->register(SchedulerPreflightCheck::class, SchedulerPreflightCheck::class)
                ->setArgument('$doctor', new Reference(SchedulerDoctor::class))
                ->setAutoconfigured(true)
                ->setPublic(false);
        }
    }

    private function registerConsoleCommands(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(ScheduleService::class)) {
            $container->register(ScheduleListCommand::class, ScheduleListCommand::class)
                ->setArgument('$resolver', new Reference(ScheduleResolver::class))
                ->addTag('console.command')
                ->setPublic(false);

            $container->register(ScheduleRunNowCommand::class, ScheduleRunNowCommand::class)
                ->setArgument('$service', new Reference(ScheduleService::class))
                ->addTag('console.command')
                ->setPublic(false);

            $container->register(SchedulePauseCommand::class, SchedulePauseCommand::class)
                ->setArgument('$service', new Reference(ScheduleService::class))
                ->addTag('console.command')
                ->setPublic(false);

            $container->register(ScheduleResumeCommand::class, ScheduleResumeCommand::class)
                ->setArgument('$service', new Reference(ScheduleService::class))
                ->addTag('console.command')
                ->setPublic(false);

            $container->register(SchedulePruneCommand::class, SchedulePruneCommand::class)
                ->setArgument('$runStore', new Reference(ScheduleRunStoreInterface::class))
                ->setArgument('$sweeper', new Reference(RunRetentionSweeper::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->setArgument('$audit', new Reference(SchedulerAuditProjector::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                ->addTag('console.command')
                ->setPublic(false);

            $container->register(RetentionOverrideSetCommand::class, RetentionOverrideSetCommand::class)
                ->setArgument('$service', new Reference(ScheduleService::class))
                ->addTag('console.command')
                ->setPublic(false);

            $container->register(RetentionOverrideRemoveCommand::class, RetentionOverrideRemoveCommand::class)
                ->setArgument('$service', new Reference(ScheduleService::class))
                ->addTag('console.command')
                ->setPublic(false);
        }

        if ($container->hasDefinition(FourEyesGate::class)) {
            $container->register(ScheduleApproveCommand::class, ScheduleApproveCommand::class)
                ->setArgument('$fourEyesGate', new Reference(FourEyesGate::class))
                ->addTag('console.command')
                ->setPublic(false);
        }

        if ($container->hasDefinition(SchedulerDoctor::class)) {
            $container->register(ScheduleDoctorCommand::class, ScheduleDoctorCommand::class)
                ->setArgument('$doctor', new Reference(SchedulerDoctor::class))
                ->addTag('console.command')
                ->setPublic(false);
        }
    }

}
