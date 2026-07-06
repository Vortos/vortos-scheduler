<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Validates the vortos_scheduler configuration tree.
 *
 * All nodes have defaults — no config file is required.
 * Root node alias must match SchedulerExtension::getAlias(): 'vortos_scheduler'
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('vortos_scheduler');

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('resolver_cache_ttl_sec')
                    ->defaultValue(5)
                    ->info('In-process TTL cache for ScheduleResolver — reduces store round-trips in CLI/admin paths')
                ->end()
                ->integerNode('prune_batch_size')
                    ->defaultValue(5000)
                    ->info('Rows deleted per chunk by the run-ledger prune sweep')
                ->end()
                ->integerNode('prune_max_duration_sec')
                    ->defaultValue(240)
                    ->info('Wall-clock budget for one prune sweep before it stops and reports partial progress')
                ->end()
                ->integerNode('max_catchup_age_sec')
                    ->defaultValue(86400)
                    ->info('How far back DueScan will fire missed schedules on catch-up')
                ->end()
                ->integerNode('assumed_done_ttl_sec')
                    ->defaultValue(3600)
                    ->info('TTL after which an in-flight run is no longer treated as overlapping')
                ->end()
                ->integerNode('circuit_breaker_failure_threshold')
                    ->defaultValue(5)
                    ->info('Consecutive dispatch failures before DispatchCircuitBreaker opens')
                ->end()
                ->integerNode('circuit_breaker_recovery_window_sec')
                    ->defaultValue(30)
                    ->info('How long DispatchCircuitBreaker stays open before allowing a retry probe')
                ->end()
                ->integerNode('approval_ttl_sec')
                    ->defaultValue(86400)
                    ->info('TTL for a pending 4-eyes approval before it expires')
                ->end()
                ->scalarNode('audit_hmac_key')
                    ->defaultValue('')
                    ->info('HMAC signing key for the audit hash-chain. Empty disables audit projection entirely.')
                ->end()
                ->integerNode('audit_epoch_size')
                    ->defaultValue(1000)
                    ->info('Entries per HMAC checkpoint epoch')
                ->end()
                ->integerNode('metrics_max_cardinality')
                    ->defaultValue(200)
                    ->info('Max distinct schedule_id label values before metrics collapse them')
                ->end()
                ->integerNode('dead_man_tolerance_sec')
                    ->defaultValue(300)
                    ->info('Grace period before DeadManDetector alerts on a schedule that should have fired but did not')
                ->end()
                ->integerNode('shard_count')
                    ->defaultValue(1)
                    ->info('Number of daemon shards for horizontal scaling of the fire loop')
                ->end()
                ->integerNode('lease_ttl_sec')
                    ->defaultValue(30)
                    ->info('Lease TTL for shard leadership')
                ->end()
                ->integerNode('max_idle_sec')
                    ->defaultValue(60)
                    ->info('Daemon poll interval when no shard has due work')
                ->end()
                ->integerNode('tenant_max_concurrent_fires')
                    ->defaultValue(0)
                    ->info('Per-tenant concurrent-fire cap in one daemon tick. 0 = unlimited.')
                ->end()
                ->scalarNode('redis_dsn')
                    ->defaultValue('redis://redis:6379')
                    ->info('Redis DSN for the redis lease driver')
                ->end()
                ->integerNode('run_retention_days')
                    ->defaultValue(30)
                    ->info('Global default retention (days) for vortos_scheduler_runs. 0 disables auto-prune entirely.')
                ->end()
                ->integerNode('fire_queue_retention_days')
                    ->defaultValue(7)
                    ->info('Retention (days) for terminal (dispatched/failed) vortos_scheduler_fire_queue rows. Transient dispatch intent — durable history lives in vortos_scheduler_runs — so it can be pruned sooner than run history. 0 disables fire-queue pruning. Pruning piggybacks on the daily auto-prune fire, so it requires run_retention_days > 0 (the default) for the schedule to exist.')
                ->end()
                ->integerNode('consume_stall_threshold_sec')
                    ->defaultValue(120)
                    ->info('SchedulerDoctor C11: how old the oldest pending fire-queue row may be before it is a Fail')
                ->end()
                ->integerNode('consume_batch_size')
                    ->defaultValue(50)
                    ->info('Rows claimed per FireQueueConsumer batch')
                ->end()
                ->integerNode('consume_poll_interval_sec')
                    ->defaultValue(2)
                    ->info('Sleep interval between empty polls in scheduler:consume --loop')
                ->end()
                ->integerNode('fire_max_attempts')
                    ->defaultValue(10)
                    ->info('R7-4: requeue an unrunnable fire this many times before dead-lettering it')
                ->end()
                ->integerNode('fire_backoff_base_sec')
                    ->defaultValue(2)
                    ->info('R7-4: exponential backoff base (seconds) between fire requeues')
                ->end()
                ->integerNode('fire_backoff_cap_sec')
                    ->defaultValue(300)
                    ->info('R7-4: maximum backoff (seconds) between fire requeues')
                ->end()
                ->scalarNode('lease_driver')
                    ->defaultValue('sql')
                    ->info('One of: sql, redis, postgres-advisory, in-memory')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
