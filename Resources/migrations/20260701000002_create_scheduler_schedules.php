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
        return 'scheduler.create_schedules';
    }

    public function description(): string
    {
        return 'Create dynamic scheduler schedules table with optimistic-lock versioning (Block S3)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('scheduler_schedules'));

        // Identity & naming
        $table->addColumn('id',         'string', ['length' => 36,  'notnull' => true]);
        $table->addColumn('name',       'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('tenant_id',  'string', ['length' => 255, 'notnull' => false]);

        // Source / status
        $table->addColumn('source',  'string', ['length' => 10, 'notnull' => true]); // 'static'|'dynamic'
        $table->addColumn('status',  'string', ['length' => 10, 'notnull' => true]); // 'active'|'paused'|'disabled'

        // Trigger (polymorphic, JSON envelope with schema_version)
        $table->addColumn('trigger_type', 'string', ['length' => 20,   'notnull' => true]); // 'recurring'|'oneshot'|'interval'
        $table->addColumn('trigger_data', 'text',   ['notnull' => true]);                   // JSON

        // Command to enqueue on fire
        $table->addColumn('command_class',   'string', ['length' => 512, 'notnull' => true]);
        $table->addColumn('command_payload', 'text',   ['notnull' => true]);                  // JSON array

        // Policies (JSON with schema_version for misfire; enum string for overlap)
        $table->addColumn('misfire_policy', 'text',   ['notnull' => true]);
        $table->addColumn('overlap_policy', 'string', ['length' => 20, 'notnull' => true]); // 'skip'|'queue'|'allow_concurrent'

        // Scheduling parameters
        $table->addColumn('timezone',       'string',  ['length' => 100, 'notnull' => true]);
        $table->addColumn('jitter_seconds', 'integer', ['notnull' => false]);
        $table->addColumn('sensitive',      'boolean', ['notnull' => true, 'default' => false]);
        $table->addColumn('metadata',       'text',    ['notnull' => true, 'default' => '{}']);

        // Optimistic-lock versioning
        $table->addColumn('version', 'integer', ['notnull' => true, 'default' => 1]);

        // Timestamps
        $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);

        // Constraints
        $table->setPrimaryKey(['id']);

        // Unique per (tenant_id, name) — includes non-null tenants.
        // NOTE: for system-scope uniqueness (tenant_id IS NULL), a partial unique index
        // must be added via raw SQL (DBAL Schema does not support partial indexes natively):
        //   CREATE UNIQUE INDEX uq_scheduler_schedules_system_name
        //       ON vortos_scheduler_schedules (name) WHERE tenant_id IS NULL;
        // This is applied by the conformance test's ensureTable() and should be added
        // in the same migration run via a separate SQL step in production.
        $table->addUniqueIndex(['tenant_id', 'name'], 'uq_scheduler_schedules_tenant_name');

        // Indexes for daemon active-scan and per-tenant listing
        $table->addIndex(['status'],    'idx_scheduler_schedules_status');
        $table->addIndex(['tenant_id'], 'idx_scheduler_schedules_tenant');
    }
};
