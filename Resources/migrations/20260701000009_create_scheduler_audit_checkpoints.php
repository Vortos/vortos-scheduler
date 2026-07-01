<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Scheduler';
    }

    public function id(): string
    {
        return 'scheduler.create_audit_checkpoints';
    }

    public function description(): string
    {
        return 'Create scheduler per-epoch HMAC audit checkpoints for O(n/epochSize) chain verification (Block S11 E5)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('scheduler_audit_checkpoints'));

        // UUID v4 primary key
        $table->addColumn('checkpoint_id', 'string', ['length' => 36, 'notnull' => true]);

        // The audit chain this checkpoint anchors — "scheduler:{tenantId ?? 'system'}:{env}"
        $table->addColumn('chain_key', 'string', ['length' => 255, 'notnull' => true]);

        // Monotonic epoch index within the chain (entry_count / epoch_size)
        $table->addColumn('epoch', 'integer', ['notnull' => true]);

        // Entries covered by this checkpoint
        $table->addColumn('entry_count', 'integer', ['notnull' => true]);

        // Last audit-log sequence folded into cumulative_hash
        $table->addColumn('last_sequence', 'integer', ['notnull' => true]);

        // Rolling hash of every entry up to last_sequence
        $table->addColumn('cumulative_hash', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]);

        // HMAC over cumulative_hash — the tamper-evidence anchor for the epoch
        $table->addColumn('hmac', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]);

        // RFC3339 timestamp
        $table->addColumn('created_at', 'string', ['length' => 32, 'notnull' => true]);

        // ── Constraints ─────────────────────────────────────────────────────────
        $table->setPrimaryKey(['checkpoint_id']);

        // Backs the repository's `ON CONFLICT (chain_key, epoch) DO NOTHING` upsert:
        // checkpoints are immutable and exactly one per (chain, epoch).
        $table->addUniqueIndex(['chain_key', 'epoch'], 'uq_scheduler_audit_ckpt_chain_epoch');

        // ── Indexes ─────────────────────────────────────────────────────────────
        // findByChainKey (verify) and findLatest (ORDER BY epoch DESC LIMIT 1)
        $table->addIndex(['chain_key', 'epoch'], 'idx_scheduler_audit_ckpt_chain');
    }
};
