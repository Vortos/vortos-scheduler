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
use Vortos\Scheduler\Console\SchedulerRunCommand;
use Vortos\Scheduler\DependencyInjection\Compiler\LeaseDriverPass;
use Vortos\Scheduler\Engine\CircuitBreaker\DispatchCircuitBreaker;
use Vortos\Scheduler\Engine\DueScan;
use Vortos\Scheduler\Engine\FireDispatcher;
use Vortos\Scheduler\Engine\MisfireResolver;
use Vortos\Scheduler\Engine\Outbox\DbalSchedulerEnqueuer;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Engine\SchedulerEnqueuerPort;
use Vortos\Scheduler\Fire\RunCompletionMiddleware;
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
use Vortos\Scheduler\Store\Dbal\DbalScheduleRunStore;
use Vortos\Scheduler\Store\Dbal\DbalScheduleStore;
use Vortos\Scheduler\Store\Dbal\ScheduleSerializer;
use Vortos\Scheduler\DependencyInjection\Compiler\StaticSchedulePass;
use Vortos\Scheduler\Registry\CachingScheduleResolver;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleDefinition;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
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
        $this->registerClock($container);
        $this->registerLeaseDrivers($container);
        $this->registerStores($container);
        $this->registerEngine($container);
        $this->registerResolver($container);
        $this->registerSecurity($container);
        $this->registerAudit($container);
        $this->registerMetrics($container);
        $this->registerObservability($container);
        $this->registerDaemon($container);
        $this->registerOverrideStore($container);
        $this->registerService($container);
        $this->registerDoctor($container);
        $this->registerConsoleCommands($container);
    }

    private function registerResolver(ContainerBuilder $container): void
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
        $resolverCacheTtl = \max(0, (int) ($_ENV['SCHEDULER_RESOLVER_CACHE_TTL_SEC'] ?? 5));
        $container->register(CachingScheduleResolver::class, CachingScheduleResolver::class)
            ->setArgument('$inner',  new Reference(ScheduleResolver::class))
            ->setArgument('$clock',  new Reference(ClockPort::class))
            ->setArgument('$ttlSec', $resolverCacheTtl)
            ->setPublic(false);
    }

    private function registerClock(ContainerBuilder $container): void
    {
        $container->register(SystemClock::class, SystemClock::class)
            ->setPublic(false);

        $container->setAlias(ClockPort::class, SystemClock::class);
        $container->setAlias(ClockInterface::class, SystemClock::class);
    }

    private function registerStores(ContainerBuilder $container): void
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
            ->setPublic(false);

        $container->setAlias(ScheduleStoreInterface::class, DbalScheduleStore::class);
        $container->setAlias(ScheduleRunStoreInterface::class, DbalScheduleRunStore::class);
    }

    private function registerEngine(ContainerBuilder $container): void
    {
        // Pure engine components — no infrastructure dependencies
        $container->register(MisfireResolver::class, MisfireResolver::class)
            ->setPublic(false);

        $maxCatchupAge = (int) ($_ENV['SCHEDULER_MAX_CATCHUP_AGE_SECONDS'] ?? 86400);
        $container->register(DueScan::class, DueScan::class)
            ->setArgument('$misfireResolver', new Reference(MisfireResolver::class))
            ->setArgument('$maxCatchupAgeSec', $maxCatchupAge)
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

        $assumedDoneTtl = (int) ($_ENV['SCHEDULER_ASSUMED_DONE_TTL_SECONDS'] ?? 3600);
        $container->register(FireDispatcher::class, FireDispatcher::class)
            ->setArgument('$runStore',         new Reference(ScheduleRunStoreInterface::class))
            ->setArgument('$enqueuer',         new Reference(SchedulerEnqueuerPort::class))
            ->setArgument('$connection',       new Reference(Connection::class))
            ->setArgument('$clock',            new Reference(ClockInterface::class))
            ->setArgument('$assumedDoneTtlSec', $assumedDoneTtl)
            ->setPublic(false);

        // E3: circuit-breaker wraps FireDispatcher; opens after N consecutive backend failures.
        $cbThreshold      = \max(1, (int) ($_ENV['SCHEDULER_CB_FAILURE_THRESHOLD'] ?? 5));
        $cbRecoveryWindow = \max(1, (int) ($_ENV['SCHEDULER_CB_RECOVERY_WINDOW_SEC'] ?? 30));
        $container->register(DispatchCircuitBreaker::class, DispatchCircuitBreaker::class)
            ->setArgument('$inner',             new Reference(FireDispatcher::class))
            ->setArgument('$clock',             new Reference(ClockPort::class))
            ->setArgument('$failureThreshold',  $cbThreshold)
            ->setArgument('$recoveryWindowSec', $cbRecoveryWindow)
            ->setPublic(false);

        $container->setAlias(FireDispatcherPort::class, DispatchCircuitBreaker::class);

        // RunCompletionMiddleware: registered as consumer middleware (vortos.middleware, priority 50).
        // Transitions the fire-ledger run state to Completed inside TransactionalMiddleware's TX.
        $container->register(RunCompletionMiddleware::class, RunCompletionMiddleware::class)
            ->setArgument('$runStore', new Reference(ScheduleRunStoreInterface::class))
            ->setArgument('$clock',    new Reference(ClockInterface::class))
            ->addTag('vortos.middleware', ['priority' => 50])
            ->setPublic(false);
    }

    private function registerSecurity(ContainerBuilder $container): void
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
            $approvalTtl = (int) ($_ENV['SCHEDULER_APPROVAL_TTL_SECONDS'] ?? 86400);
            $container->register(FourEyesGate::class, FourEyesGate::class)
                ->setArgument('$store',          new Reference(DbalFourEyesApprovalStore::class))
                ->setArgument('$clock',          new Reference(ClockInterface::class))
                ->setArgument('$approvalTtlSec', $approvalTtl)
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

    private function registerAudit(ContainerBuilder $container): void
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

        $hmacKey = (string) ($_ENV['SCHEDULER_AUDIT_HMAC_KEY'] ?? '');
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
            $epochSize = \max(1, (int) ($_ENV['SCHEDULER_AUDIT_EPOCH_SIZE'] ?? 1000));
            $container->register(SchedulerAuditCheckpointProjector::class, SchedulerAuditCheckpointProjector::class)
                ->setArgument('$repository', new Reference(SchedulerAuditCheckpointRepositoryInterface::class))
                ->setArgument('$hmacKey',    $hmacKey)
                ->setArgument('$epochSize',  $epochSize)
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

    private function registerMetrics(ContainerBuilder $container): void
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
        $maxCardinality = \max(1, (int) ($_ENV['SCHEDULER_METRICS_MAX_CARDINALITY'] ?? 200));
        $container->register(CardinalityGuardedSchedulerMetrics::class, CardinalityGuardedSchedulerMetrics::class)
            ->setArgument('$inner', new Reference(SchedulerMetrics::class))
            ->setArgument('$metrics', $metricsRef)
            ->setArgument('$maxDistinctSchedules', $maxCardinality)
            ->setPublic(false);

        $container->setAlias(SchedulerMetricsPort::class, CardinalityGuardedSchedulerMetrics::class);
    }

    private function registerObservability(ContainerBuilder $container): void
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
            $toleranceSec = \max(1, (int) ($_ENV['SCHEDULER_DEADMAN_TOLERANCE_SEC'] ?? 300));
            $env          = (string) ($_ENV['APP_ENV'] ?? 'production');

            $container->register(DeadManDetector::class, DeadManDetector::class)
                ->setArgument('$runStore',        new Reference(ScheduleRunStoreInterface::class))
                ->setArgument('$dispatcher',      new Reference($alertsClass))
                ->setArgument('$clock',           new Reference(ClockPort::class))
                ->setArgument('$env',             $env)
                ->setArgument('$defaultToleranceSec', $toleranceSec)
                ->setArgument('$logger',          new Reference(LoggerInterface::class))
                ->setPublic(false);
        }
    }

    private function registerDaemon(ContainerBuilder $container): void
    {
        $shardCount     = \max(1, (int) ($_ENV['SCHEDULER_SHARD_COUNT'] ?? 1));
        $leaseTtl       = \max(5, (int) ($_ENV['SCHEDULER_LEASE_TTL_SEC'] ?? 30));
        $maxIdle        = \max(1, (int) ($_ENV['SCHEDULER_MAX_IDLE_SEC'] ?? 60));
        $tenantMaxFires = \max(0, (int) ($_ENV['SCHEDULER_TENANT_MAX_CONCURRENT_FIRES'] ?? 0));

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
            ->setArgument('$shardCount',              $shardCount)
            ->setArgument('$leaseTtlSec',             $leaseTtl)
            ->setArgument('$maxIdleSec',              $maxIdle)
            ->setArgument('$tenantMaxConcurrentFires', $tenantMaxFires)
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

    private function registerLeaseDrivers(ContainerBuilder $container): void
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
            $redisDsn = (string) ($_ENV['VORTOS_CACHE_DSN'] ?? 'redis://redis:6379');

            $container->register('vortos_scheduler.redis', \Redis::class)
                ->setFactory([RedisConnectionFactory::class, 'fromDsn'])
                ->setArgument(0, $redisDsn)
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

    private function registerService(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ScheduleResolver::class)) {
            return;
        }

        if (!$container->hasAlias(ScheduleStatusOverrideStoreInterface::class)
            && !$container->hasDefinition(ScheduleStatusOverrideStoreInterface::class)) {
            return;
        }

        $container->register(ScheduleService::class, ScheduleService::class)
            ->setArgument('$staticRegistry', new Reference(StaticScheduleRegistry::class))
            ->setArgument('$dynamicStore', new Reference(ScheduleStoreInterface::class))
            ->setArgument('$overrideStore', new Reference(ScheduleStatusOverrideStoreInterface::class))
            ->setArgument('$policy', new Reference(SchedulePolicyInterface::class))
            ->setArgument('$clock', new Reference(ClockPort::class))
            ->setArgument('$fireDispatcher', new Reference(FireDispatcherPort::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$fourEyesGate', new Reference(FourEyesGate::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$audit', new Reference(SchedulerAuditProjector::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);
    }

    private function registerDoctor(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ScheduleResolver::class)) {
            return;
        }

        $maxCatchupAge = (int) ($_ENV['SCHEDULER_MAX_CATCHUP_AGE_SECONDS'] ?? 86400);
        $shardCount    = max(1, (int) ($_ENV['SCHEDULER_SHARD_COUNT'] ?? 1));
        $prefix        = $container->hasParameter('vortos.db.framework_table_prefix')
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
            ->setArgument('$shardCount', $shardCount)
            ->setArgument('$maxCatchupAgeSec', $maxCatchupAge)
            ->setPublic(false);

        // D: deploy:doctor gate — only registered when vortos-deploy is installed.
        if (\interface_exists(\Vortos\Deploy\Preflight\PreflightCheckInterface::class)) {
            $container->register(SchedulerPreflightCheck::class, SchedulerPreflightCheck::class)
                ->setArgument('$doctor', new Reference(SchedulerDoctor::class))
                ->addTag('vortos.preflight_check')
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
