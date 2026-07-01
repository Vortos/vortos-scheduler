<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit\Dbal;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Vortos\Observability\Audit\AuditHashChain;
use Vortos\Scheduler\Audit\SchedulerAuditEntry;
use Vortos\Scheduler\Audit\SchedulerAuditRepositoryInterface;

/**
 * Default (DBAL) append-only store for the scheduler audit ledger (S8).
 *
 * Concurrency safety: a Postgres advisory transaction lock keyed on hashtext(chainKey)
 * serialises concurrent appends to the same chain — the last writer determines the tail
 * and the losing concurrent write simply retries. On non-Postgres platforms (e.g. SQLite
 * in unit tests) the advisory lock is a no-op; the surrounding DBAL transaction already
 * serialises on a single connection.
 *
 * This is intentionally append-only: no UPDATE or DELETE path exists at any level.
 */
final class DbalSchedulerAuditRepository implements SchedulerAuditRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function appendNext(string $chainKey, callable $builder): SchedulerAuditEntry
    {
        return $this->connection->transactional(
            function (Connection $conn) use ($chainKey, $builder): SchedulerAuditEntry {
                // Advisory lock on the chain key serialises concurrent appenders (Postgres only).
                if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                    $conn->executeStatement(
                        'SELECT pg_advisory_xact_lock(hashtext(:key))',
                        ['key' => $chainKey],
                    );
                }

                // Find current tail for this chain
                $tail = $conn->fetchAssociative(
                    sprintf(
                        'SELECT sequence, content_hash FROM %s WHERE chain_key = :key ORDER BY sequence DESC LIMIT 1',
                        $this->table,
                    ),
                    ['key' => $chainKey],
                );

                $nextSequence = $tail === false ? 0 : ((int) $tail['sequence']) + 1;
                $prevHash     = $tail === false ? AuditHashChain::GENESIS_HASH : (string) $tail['content_hash'];

                /** @var SchedulerAuditEntry $entry */
                $entry = $builder($nextSequence, $prevHash);

                $conn->executeStatement(
                    sprintf(
                        'INSERT INTO %s
                            (entry_id, sequence, event_type, actor_id, tenant_id, schedule_id,
                             slot, shard_index, occurred_at, data, chain_key,
                             prev_hash, content_hash, signature)
                         VALUES
                            (:entry_id, :sequence, :event_type, :actor_id, :tenant_id, :schedule_id,
                             :slot, :shard_index, :occurred_at, :data, :chain_key,
                             :prev_hash, :content_hash, :signature)',
                        $this->table,
                    ),
                    $this->toRow($entry),
                );

                return $entry;
            },
        );
    }

    /** @return list<SchedulerAuditEntry> */
    public function findByChainKey(string $chainKey, int $limit = 1000): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s WHERE chain_key = :key ORDER BY sequence ASC LIMIT :lim',
                $this->table,
            ),
            ['key' => $chainKey, 'lim' => $limit],
        );

        return array_map(fn (array $row) => SchedulerAuditEntry::fromArray($row), $rows);
    }

    /** @return list<SchedulerAuditEntry> */
    public function findBySchedule(string $scheduleId, ?string $tenantId = null, int $limit = 500): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('schedule_id = :sid')
            ->setParameter('sid', $scheduleId)
            ->orderBy('occurred_at', 'ASC')
            ->setMaxResults($limit);

        if ($tenantId !== null) {
            $qb->andWhere('tenant_id = :tid')->setParameter('tid', $tenantId);
        }

        return array_map(
            fn (array $row) => SchedulerAuditEntry::fromArray($row),
            $qb->executeQuery()->fetchAllAssociative(),
        );
    }

    /** @return list<SchedulerAuditEntry> */
    public function findByTenant(
        ?string $tenantId,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        int $limit = 1000,
    ): array {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->orderBy('chain_key', 'ASC')
            ->addOrderBy('sequence', 'ASC')
            ->setMaxResults($limit);

        if ($tenantId !== null) {
            $qb->andWhere('tenant_id = :tid')->setParameter('tid', $tenantId);
        } else {
            $qb->andWhere('tenant_id IS NULL');
        }

        if ($from !== null) {
            $qb->andWhere('occurred_at >= :from')->setParameter('from', $from->format(DateTimeInterface::ATOM));
        }

        if ($to !== null) {
            $qb->andWhere('occurred_at <= :to')->setParameter('to', $to->format(DateTimeInterface::ATOM));
        }

        return array_map(
            fn (array $row) => SchedulerAuditEntry::fromArray($row),
            $qb->executeQuery()->fetchAllAssociative(),
        );
    }

    public function stream(
        ?string $chainKey = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): \Generator {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->orderBy('chain_key', 'ASC')
            ->addOrderBy('sequence', 'ASC');

        if ($chainKey !== null) {
            $qb->andWhere('chain_key = :key')->setParameter('key', $chainKey);
        }

        if ($from !== null) {
            $qb->andWhere('occurred_at >= :from')->setParameter('from', $from->format(DateTimeInterface::ATOM));
        }

        if ($to !== null) {
            $qb->andWhere('occurred_at <= :to')->setParameter('to', $to->format(DateTimeInterface::ATOM));
        }

        $result = $qb->executeQuery();

        while ($row = $result->fetchAssociative()) {
            yield SchedulerAuditEntry::fromArray($row);
        }
    }

    /** @return array<string, mixed> */
    private function toRow(SchedulerAuditEntry $entry): array
    {
        return [
            'entry_id'     => $entry->entryId,
            'sequence'     => $entry->sequence,
            'event_type'   => $entry->eventType,
            'actor_id'     => $entry->actorId,
            'tenant_id'    => $entry->tenantId,
            'schedule_id'  => $entry->scheduleId,
            'slot'         => $entry->slot,
            'shard_index'  => $entry->shardIndex,
            'occurred_at'  => $entry->occurredAt,
            'data'         => json_encode($entry->data, JSON_THROW_ON_ERROR),
            'chain_key'    => $entry->chainKey,
            'prev_hash'    => $entry->prevHash,
            'content_hash' => $entry->contentHash,
            'signature'    => $entry->signature,
        ];
    }
}
