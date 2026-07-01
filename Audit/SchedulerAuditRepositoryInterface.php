<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit;

/**
 * PORT — append-only audit ledger for the scheduler.
 *
 * Every append acquires a per-chain advisory lock (on Postgres) or relies on the
 * transaction's own serialization (SQLite) so the chain's sequence is gapless.
 * There is intentionally no delete or update method — the ledger is append-only
 * by design; audit chain integrity depends on immutability.
 */
interface SchedulerAuditRepositoryInterface
{
    /**
     * Atomically append the next entry to the chain identified by $chainKey.
     *
     * $builder receives (int $nextSequence, string $prevHash) and must return a fully
     * constructed {@see SchedulerAuditEntry} (exactly as DbalDeployAuditViewRepository does).
     */
    public function appendNext(string $chainKey, callable $builder): SchedulerAuditEntry;

    /**
     * Return all entries for a chain in sequence order.
     *
     * @return list<SchedulerAuditEntry>
     */
    public function findByChainKey(string $chainKey, int $limit = 1000): array;

    /**
     * Return all entries associated with a specific schedule.
     *
     * @return list<SchedulerAuditEntry>
     */
    public function findBySchedule(string $scheduleId, ?string $tenantId = null, int $limit = 500): array;

    /**
     * Return entries for a tenant (or system-wide when $tenantId is null) in time order.
     *
     * @return list<SchedulerAuditEntry>
     */
    public function findByTenant(
        ?string $tenantId,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        int $limit = 1000,
    ): array;

    /**
     * Stream all entries (optionally filtered) without loading the full table.
     * Designed for the scheduler:audit:export command (S9 seam).
     *
     * @return \Generator<SchedulerAuditEntry>
     */
    public function stream(
        ?string $chainKey = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): \Generator;
}
