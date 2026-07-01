<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Audit\Dbal;

use Doctrine\DBAL\Connection;
use Vortos\Scheduler\Audit\SchedulerAuditCheckpoint;
use Vortos\Scheduler\Audit\SchedulerAuditCheckpointRepositoryInterface;

/**
 * DBAL persistence for per-epoch audit chain checkpoints (S11).
 *
 * Checkpoints are immutable once written. There is no update or delete path —
 * their value is in asserting the integrity of a completed epoch; mutation would
 * defeat the purpose.
 *
 * Table schema: scheduler_audit_checkpoints
 *   checkpoint_id  VARCHAR(36) PRIMARY KEY
 *   chain_key      VARCHAR(255) NOT NULL
 *   epoch          INT NOT NULL
 *   entry_count    INT NOT NULL
 *   last_sequence  INT NOT NULL
 *   cumulative_hash VARCHAR(64) NOT NULL
 *   hmac            VARCHAR(64) NOT NULL
 *   created_at      VARCHAR(32) NOT NULL
 *   UNIQUE (chain_key, epoch)
 */
final class DbalSchedulerAuditCheckpointRepository implements SchedulerAuditCheckpointRepositoryInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function save(SchedulerAuditCheckpoint $checkpoint): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (checkpoint_id, chain_key, epoch, entry_count, last_sequence, cumulative_hash, hmac, created_at)
                 VALUES (:checkpoint_id, :chain_key, :epoch, :entry_count, :last_sequence, :cumulative_hash, :hmac, :created_at)
                 ON CONFLICT (chain_key, epoch) DO NOTHING',
                $this->table,
            ),
            [
                'checkpoint_id'   => $checkpoint->checkpointId,
                'chain_key'       => $checkpoint->chainKey,
                'epoch'           => $checkpoint->epoch,
                'entry_count'     => $checkpoint->entryCount,
                'last_sequence'   => $checkpoint->lastSequence,
                'cumulative_hash' => $checkpoint->cumulativeHash,
                'hmac'            => $checkpoint->hmac,
                'created_at'      => $checkpoint->createdAt,
            ],
        );
    }

    public function findByChainKey(string $chainKey, int $fromEpoch = 0): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT * FROM %s WHERE chain_key = :key AND epoch >= :from_epoch ORDER BY epoch ASC',
                $this->table,
            ),
            ['key' => $chainKey, 'from_epoch' => $fromEpoch],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function findLatest(string $chainKey): ?SchedulerAuditCheckpoint
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT * FROM %s WHERE chain_key = :key ORDER BY epoch DESC LIMIT 1',
                $this->table,
            ),
            ['key' => $chainKey],
        );

        return $row === false ? null : self::hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): SchedulerAuditCheckpoint
    {
        return new SchedulerAuditCheckpoint(
            checkpointId:   (string) $row['checkpoint_id'],
            chainKey:       (string) $row['chain_key'],
            epoch:          (int)    $row['epoch'],
            entryCount:     (int)    $row['entry_count'],
            lastSequence:   (int)    $row['last_sequence'],
            cumulativeHash: (string) $row['cumulative_hash'],
            hmac:           (string) $row['hmac'],
            createdAt:      (string) $row['created_at'],
        );
    }
}
