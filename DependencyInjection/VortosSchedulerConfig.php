<?php

declare(strict_types=1);

namespace Vortos\Scheduler\DependencyInjection;

/**
 * Fluent configuration object for vortos-scheduler.
 *
 * Loaded via require in SchedulerExtension::load(). Every setting has a sensible
 * default (identical to the env-var defaults this class replaces) — no config file
 * required for basic usage.
 *
 * ## Standard usage
 *
 * Create config/scheduler.php in your project:
 *
 *   return static function (VortosSchedulerConfig $config): void {
 *       $config
 *           ->runRetentionDays(90)
 *           ->shardCount(4)
 *           ->leaseDriver('postgres-advisory');
 *   };
 *
 * Env-specific overrides go in config/{env}/scheduler.php (e.g. config/test/scheduler.php),
 * loaded after the base file so they take precedence.
 *
 * Every property here previously lived as a scattered `$_ENV['SCHEDULER_...']` read
 * across SchedulerExtension.php (and LeaseDriverPass / SchedulerConsumeCommand). The
 * env vars still work unchanged as defaults — this class only adds a single,
 * discoverable, type-checked place to see and override every setting.
 */
final class VortosSchedulerConfig
{
    private int $resolverCacheTtlSec;
    private int $pruneBatchSize;
    private int $pruneMaxDurationSec;
    private int $maxCatchupAgeSec;
    private int $assumedDoneTtlSec;
    private int $circuitBreakerFailureThreshold;
    private int $circuitBreakerRecoveryWindowSec;
    private int $approvalTtlSec;
    private string $auditHmacKey;
    private int $auditEpochSize;
    private int $metricsMaxCardinality;
    private int $deadManToleranceSec;
    private int $shardCount;
    private int $leaseTtlSec;
    private int $maxIdleSec;
    private int $tenantMaxConcurrentFires;
    private string $redisDsn;
    private int $runRetentionDays;
    private int $fireQueueRetentionDays;
    private int $consumeStallThresholdSec;
    private int $consumeBatchSize;
    private int $consumePollIntervalSec;
    private int $fireMaxAttempts;
    private int $fireBackoffBaseSec;
    private int $fireBackoffCapSec;
    private string $leaseDriver;

    public function __construct()
    {
        $this->resolverCacheTtlSec             = \max(0, (int) ($_ENV['SCHEDULER_RESOLVER_CACHE_TTL_SEC'] ?? 5));
        $this->pruneBatchSize                  = \max(1, (int) ($_ENV['SCHEDULER_PRUNE_BATCH_SIZE'] ?? 5000));
        $this->pruneMaxDurationSec             = \max(0, (int) ($_ENV['SCHEDULER_PRUNE_MAX_DURATION_SEC'] ?? 240));
        $this->maxCatchupAgeSec                = (int) ($_ENV['SCHEDULER_MAX_CATCHUP_AGE_SECONDS'] ?? 86400);
        $this->assumedDoneTtlSec               = (int) ($_ENV['SCHEDULER_ASSUMED_DONE_TTL_SECONDS'] ?? 3600);
        $this->circuitBreakerFailureThreshold  = \max(1, (int) ($_ENV['SCHEDULER_CB_FAILURE_THRESHOLD'] ?? 5));
        $this->circuitBreakerRecoveryWindowSec = \max(1, (int) ($_ENV['SCHEDULER_CB_RECOVERY_WINDOW_SEC'] ?? 30));
        $this->approvalTtlSec                  = (int) ($_ENV['SCHEDULER_APPROVAL_TTL_SECONDS'] ?? 86400);
        $this->auditHmacKey                    = (string) ($_ENV['SCHEDULER_AUDIT_HMAC_KEY'] ?? '');
        $this->auditEpochSize                  = \max(1, (int) ($_ENV['SCHEDULER_AUDIT_EPOCH_SIZE'] ?? 1000));
        $this->metricsMaxCardinality           = \max(1, (int) ($_ENV['SCHEDULER_METRICS_MAX_CARDINALITY'] ?? 200));
        $this->deadManToleranceSec             = \max(1, (int) ($_ENV['SCHEDULER_DEADMAN_TOLERANCE_SEC'] ?? 300));
        $this->shardCount                      = \max(1, (int) ($_ENV['SCHEDULER_SHARD_COUNT'] ?? 1));
        $this->leaseTtlSec                     = \max(5, (int) ($_ENV['SCHEDULER_LEASE_TTL_SEC'] ?? 30));
        $this->maxIdleSec                      = \max(1, (int) ($_ENV['SCHEDULER_MAX_IDLE_SEC'] ?? 60));
        $this->tenantMaxConcurrentFires         = \max(0, (int) ($_ENV['SCHEDULER_TENANT_MAX_CONCURRENT_FIRES'] ?? 0));
        $this->redisDsn                        = (string) ($_ENV['VORTOS_CACHE_DSN'] ?? 'redis://redis:6379');
        $this->runRetentionDays                = \max(0, (int) ($_ENV['SCHEDULER_RUN_RETENTION_DAYS'] ?? 30));
        $this->fireQueueRetentionDays          = \max(0, (int) ($_ENV['SCHEDULER_FIRE_QUEUE_RETENTION_DAYS'] ?? 7));
        $this->consumeStallThresholdSec        = \max(1, (int) ($_ENV['SCHEDULER_CONSUME_STALL_THRESHOLD_SEC'] ?? 120));
        $this->consumeBatchSize                = \max(1, (int) ($_ENV['SCHEDULER_CONSUME_BATCH_SIZE'] ?? 50));
        $this->consumePollIntervalSec           = \max(1, (int) ($_ENV['SCHEDULER_CONSUME_POLL_INTERVAL_SEC'] ?? 2));
        $this->fireMaxAttempts                  = \max(1, (int) ($_ENV['SCHEDULER_FIRE_MAX_ATTEMPTS'] ?? 10));
        $this->fireBackoffBaseSec               = \max(1, (int) ($_ENV['SCHEDULER_FIRE_BACKOFF_BASE_SEC'] ?? 2));
        $this->fireBackoffCapSec                = \max(1, (int) ($_ENV['SCHEDULER_FIRE_BACKOFF_CAP_SEC'] ?? 300));
        $this->leaseDriver                     = (string) ($_ENV['VORTOS_SCHEDULER_LEASE_DRIVER'] ?? 'sql');
    }

    /** In-process TTL cache for ScheduleResolver — reduces store round-trips in CLI/admin paths. */
    public function resolverCacheTtlSec(int $seconds): static
    {
        $this->resolverCacheTtlSec = \max(0, $seconds);
        return $this;
    }

    /** Rows deleted per chunk by the run-ledger prune sweep (auto and manual). */
    public function pruneBatchSize(int $rows): static
    {
        $this->pruneBatchSize = \max(1, $rows);
        return $this;
    }

    /** Wall-clock budget for one prune sweep; over budget, it stops and reports partial progress. */
    public function pruneMaxDurationSec(int $seconds): static
    {
        $this->pruneMaxDurationSec = \max(0, $seconds);
        return $this;
    }

    /** How far back DueScan will fire missed schedules on catch-up. */
    public function maxCatchupAgeSec(int $seconds): static
    {
        $this->maxCatchupAgeSec = $seconds;
        return $this;
    }

    /** TTL after which an in-flight run is no longer treated as overlapping. */
    public function assumedDoneTtlSec(int $seconds): static
    {
        $this->assumedDoneTtlSec = $seconds;
        return $this;
    }

    /** Consecutive dispatch failures before DispatchCircuitBreaker opens. */
    public function circuitBreakerFailureThreshold(int $failures): static
    {
        $this->circuitBreakerFailureThreshold = \max(1, $failures);
        return $this;
    }

    /** How long DispatchCircuitBreaker stays open before allowing a retry probe. */
    public function circuitBreakerRecoveryWindowSec(int $seconds): static
    {
        $this->circuitBreakerRecoveryWindowSec = \max(1, $seconds);
        return $this;
    }

    /** TTL for a pending 4-eyes approval before it expires. */
    public function approvalTtlSec(int $seconds): static
    {
        $this->approvalTtlSec = $seconds;
        return $this;
    }

    /** HMAC signing key for the audit hash-chain. Empty string disables audit projection entirely. */
    public function auditHmacKey(string $key): static
    {
        $this->auditHmacKey = $key;
        return $this;
    }

    /** Entries per HMAC checkpoint epoch — controls O(n/epochSize) chain-verification cost. */
    public function auditEpochSize(int $entries): static
    {
        $this->auditEpochSize = \max(1, $entries);
        return $this;
    }

    /** Max distinct schedule_id label values before CardinalityGuardedSchedulerMetrics collapses them. */
    public function metricsMaxCardinality(int $distinctSchedules): static
    {
        $this->metricsMaxCardinality = \max(1, $distinctSchedules);
        return $this;
    }

    /** Grace period before DeadManDetector alerts on a schedule that should have fired but didn't. */
    public function deadManToleranceSec(int $seconds): static
    {
        $this->deadManToleranceSec = \max(1, $seconds);
        return $this;
    }

    /** Number of daemon shards for horizontal scaling of the fire loop. */
    public function shardCount(int $count): static
    {
        $this->shardCount = \max(1, $count);
        return $this;
    }

    /** Lease TTL for shard leadership; must comfortably exceed one tick's worst-case duration. */
    public function leaseTtlSec(int $seconds): static
    {
        $this->leaseTtlSec = \max(5, $seconds);
        return $this;
    }

    /** Daemon poll interval when no shard has due work. */
    public function maxIdleSec(int $seconds): static
    {
        $this->maxIdleSec = \max(1, $seconds);
        return $this;
    }

    /** Per-tenant concurrent-fire cap in one daemon tick. 0 = unlimited. */
    public function tenantMaxConcurrentFires(int $count): static
    {
        $this->tenantMaxConcurrentFires = \max(0, $count);
        return $this;
    }

    /**
     * Redis DSN for the redis lease driver. Shares VORTOS_CACHE_DSN with vortos-cache
     * by default — override here independently if the scheduler needs its own instance.
     */
    public function redisDsn(string $dsn): static
    {
        $this->redisDsn = $dsn;
        return $this;
    }

    /** Global default retention (days) for vortos_scheduler_runs. 0 disables auto-prune entirely. */
    public function runRetentionDays(int $days): static
    {
        $this->runRetentionDays = \max(0, $days);
        return $this;
    }

    /** Retention (days) for terminal fire-queue rows. 0 disables fire-queue pruning. */
    public function fireQueueRetentionDays(int $days): static
    {
        $this->fireQueueRetentionDays = \max(0, $days);
        return $this;
    }

    /** SchedulerDoctor C11: how old the oldest pending fire-queue row may be before it's a Fail. */
    public function consumeStallThresholdSec(int $seconds): static
    {
        $this->consumeStallThresholdSec = \max(1, $seconds);
        return $this;
    }

    /** Rows claimed per FireQueueConsumer batch. */
    public function consumeBatchSize(int $rows): static
    {
        $this->consumeBatchSize = \max(1, $rows);
        return $this;
    }

    /** Sleep interval between empty polls in `scheduler:consume --loop`. */
    public function consumePollIntervalSec(int $seconds): static
    {
        $this->consumePollIntervalSec = \max(1, $seconds);
        return $this;
    }

    /**
     * Lease driver key. One of: 'sql', 'redis', 'postgres-advisory', 'in-memory'.
     * Validated against the real driver map at compile time by LeaseDriverPass —
     * an unknown key here still fails loudly, just later (container compile, not construction).
     */
    public function leaseDriver(string $driver): static
    {
        $this->leaseDriver = $driver;
        return $this;
    }


    /** @internal Used by SchedulerExtension to validate/normalize via Configuration's tree. */
    public function toArray(): array
    {
        return [
            'resolver_cache_ttl_sec'             => $this->resolverCacheTtlSec,
            'prune_batch_size'                   => $this->pruneBatchSize,
            'prune_max_duration_sec'              => $this->pruneMaxDurationSec,
            'max_catchup_age_sec'                => $this->maxCatchupAgeSec,
            'assumed_done_ttl_sec'                => $this->assumedDoneTtlSec,
            'circuit_breaker_failure_threshold'  => $this->circuitBreakerFailureThreshold,
            'circuit_breaker_recovery_window_sec' => $this->circuitBreakerRecoveryWindowSec,
            'approval_ttl_sec'                    => $this->approvalTtlSec,
            'audit_hmac_key'                      => $this->auditHmacKey,
            'audit_epoch_size'                    => $this->auditEpochSize,
            'metrics_max_cardinality'              => $this->metricsMaxCardinality,
            'dead_man_tolerance_sec'               => $this->deadManToleranceSec,
            'shard_count'                          => $this->shardCount,
            'lease_ttl_sec'                        => $this->leaseTtlSec,
            'max_idle_sec'                         => $this->maxIdleSec,
            'tenant_max_concurrent_fires'           => $this->tenantMaxConcurrentFires,
            'redis_dsn'                            => $this->redisDsn,
            'run_retention_days'                   => $this->runRetentionDays,
            'fire_queue_retention_days'             => $this->fireQueueRetentionDays,
            'consume_stall_threshold_sec'           => $this->consumeStallThresholdSec,
            'consume_batch_size'                    => $this->consumeBatchSize,
            'consume_poll_interval_sec'             => $this->consumePollIntervalSec,
            'fire_max_attempts'                     => $this->fireMaxAttempts,
            'fire_backoff_base_sec'                 => $this->fireBackoffBaseSec,
            'fire_backoff_cap_sec'                  => $this->fireBackoffCapSec,
            'lease_driver'                          => $this->leaseDriver,
        ];
    }
}
