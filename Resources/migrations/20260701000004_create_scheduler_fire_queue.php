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
        return 'scheduler.create_fire_queue';
    }

    public function description(): string
    {
        return 'Create scheduler fire-queue for in-process command dispatch (Block S4)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('scheduler_fire_queue'));

        // Row identity
        $table->addColumn('id', 'guid', ['notnull' => true]);

        // run_id mirrors the fire-ledger PK — sha256(slotKey), 64 hex chars.
        // UNIQUE prevents double-enqueue if FireDispatcher retries (idempotency at queue level).
        $table->addColumn('run_id', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]);

        // Scheduler context
        $table->addColumn('schedule_id',  'string', ['length' => 36,  'notnull' => true]);
        $table->addColumn('tenant_id',    'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('slot',         'text',   ['notnull' => true]);
        $table->addColumn('scheduled_for','datetime_immutable', ['notnull' => true]);

        // Command to dispatch
        $table->addColumn('command_class',   'text', ['notnull' => true]);
        $table->addColumn('command_payload', 'text', ['notnull' => true]); // JSON

        // RunStamp headers (X-Scheduler-*) for FireQueueConsumer (S12)
        $table->addColumn('metadata', 'text', ['notnull' => false]); // JSON nullable

        // Lifecycle
        $table->addColumn('status',         'string', ['length' => 20, 'notnull' => true, 'default' => 'pending']);
        $table->addColumn('created_at',     'datetime_immutable', ['notnull' => true]);
        $table->addColumn('dispatched_at',  'datetime_immutable', ['notnull' => false]);
        $table->addColumn('failure_reason', 'text', ['notnull' => false]);

        // ── Constraints ─────────────────────────────────────────────────────────
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['run_id'], 'uq_scheduler_fire_queue_run_id');

        // ── Indexes ─────────────────────────────────────────────────────────────
        // Daemon polling: pending rows by schedule order
        $table->addIndex(['status', 'created_at'], 'idx_sched_fq_status_created');
        // Per-tenant visibility
        $table->addIndex(['tenant_id', 'status'], 'idx_sched_fq_tenant_status');
    }
};
