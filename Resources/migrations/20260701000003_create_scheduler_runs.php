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
        return 'scheduler.create_runs';
    }

    public function description(): string
    {
        return 'Create scheduler fire-ledger (run history + idempotency anchor) (Block S3)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('scheduler_runs'));

        // Primary key: sha256(slotKey) — 64 hex chars, fixed-width for B-tree efficiency
        $table->addColumn('run_id', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]);

        // Identity columns
        $table->addColumn('schedule_id', 'string', ['length' => 36,  'notnull' => true]);
        $table->addColumn('tenant_id',   'string', ['length' => 255, 'notnull' => false]);

        // Slot key (human-readable for operator diagnostics)
        $table->addColumn('slot', 'text', ['notnull' => true]);

        // Timestamps
        $table->addColumn('scheduled_for', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('dispatched_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('completed_at',  'datetime_immutable', ['notnull' => false]);

        // Run lifecycle
        $table->addColumn('run_state', 'string', ['length' => 20, 'notnull' => true, 'default' => 'dispatched']);
        $table->addColumn('attempt',   'smallint', ['notnull' => true, 'default' => 1]);

        // ── Constraints ─────────────────────────────────────────────────────────
        $table->setPrimaryKey(['run_id']);

        // THE IDEMPOTENCY ANCHOR (spec §4.3):
        // A duplicate INSERT on (tenant_id, schedule_id, slot) fails with
        // UniqueConstraintViolationException → DuplicateSlotException → exactly-once-effect.
        $table->addUniqueIndex(['tenant_id', 'schedule_id', 'slot'], 'uq_scheduler_runs_slot');

        // ── Indexes ─────────────────────────────────────────────────────────────

        // Overlap check: "is the prior run for schedule X still dispatched?"
        $table->addIndex(['schedule_id', 'run_state'], 'idx_scheduler_runs_schedule_state');

        // findLastSlots() DISTINCT ON — dispatched_at drives the "latest" ordering
        $table->addIndex(['schedule_id', 'dispatched_at'], 'idx_scheduler_runs_schedule_dispatched');

        // Dead-man detection + per-tenant run history
        $table->addIndex(['tenant_id', 'scheduled_for'], 'idx_scheduler_runs_tenant_scheduled');

        // pruneOldRuns() range delete
        $table->addIndex(['dispatched_at', 'run_state'], 'idx_scheduler_runs_prune');
    }
};
