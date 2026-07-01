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
        return 'scheduler.create_audit_log';
    }

    public function description(): string
    {
        return 'Create scheduler tamper-evident hash-chained audit ledger (Block S8)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('scheduler_audit_log'));

        // UUID v4 primary key
        $table->addColumn('entry_id', 'string', ['length' => 36, 'notnull' => true]);

        // Sequence is monotonic per chain_key (not globally unique)
        $table->addColumn('sequence', 'integer', ['notnull' => true]);

        // SchedulerAuditEvent value — e.g. 'fire.dispatched', 'schedule.paused'
        $table->addColumn('event_type', 'string', ['length' => 64, 'notnull' => true]);

        // Actor: userId or 'system'
        $table->addColumn('actor_id', 'string', ['length' => 255, 'notnull' => true]);

        // Scope (null = system-wide)
        $table->addColumn('tenant_id', 'string', ['length' => 255, 'notnull' => false]);

        // Present on schedule mutation + fire events
        $table->addColumn('schedule_id', 'string', ['length' => 255, 'notnull' => false]);

        // Present on fire events: the deterministic idempotency slot key
        $table->addColumn('slot', 'text', ['notnull' => false]);

        // Present on leader events
        $table->addColumn('shard_index', 'integer', ['notnull' => false]);

        // RFC3339 timestamp
        $table->addColumn('occurred_at', 'string', ['length' => 32, 'notnull' => true]);

        // Scrubbed event-specific payload (JSON)
        $table->addColumn('data', 'text', ['notnull' => true]);

        // Chain identity: "scheduler:{tenantId ?? 'system'}:{env}"
        // Separate chains per tenant allow independent export and verification.
        $table->addColumn('chain_key', 'string', ['length' => 255, 'notnull' => true]);

        // Hash chain fields — these are the tamper-evidence anchors
        $table->addColumn('prev_hash', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]);
        $table->addColumn('content_hash', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]);
        $table->addColumn('signature', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]);

        // ── Constraints ─────────────────────────────────────────────────────────
        $table->setPrimaryKey(['entry_id']);

        // THE TAMPER-EVIDENCE ANCHOR: prevents concurrent appends assigning the same
        // sequence within a chain — the loser must retry with the updated tail hash.
        $table->addUniqueIndex(['chain_key', 'sequence'], 'uq_scheduler_audit_chain_seq');

        // ── Indexes ─────────────────────────────────────────────────────────────

        // Chain walking (verify + export)
        $table->addIndex(['chain_key', 'sequence'], 'idx_scheduler_audit_chain');

        // Per-tenant audit queries and export
        $table->addIndex(['tenant_id', 'occurred_at'], 'idx_scheduler_audit_tenant_time');

        // Per-schedule history
        $table->addIndex(['schedule_id', 'occurred_at'], 'idx_scheduler_audit_schedule_time');
    }
};
