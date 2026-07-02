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
        return 'scheduler.create_cursors';
    }

    public function description(): string
    {
        return 'Create the cadence cursor table — first-class, typed cadence position per schedule, '
            . 'decoupled from the execution log (replaces slot-key parsing of scheduler_runs)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('scheduler_cursors'));

        // Keyed by schedule_id only (no FK) so static (compile-time) and dynamic (DB) schedules
        // are served uniformly — static schedules have no scheduler_schedules row.
        $table->addColumn('schedule_id', 'string', ['length' => 36, 'notnull' => true]);
        $table->addColumn('tenant_id',   'string', ['length' => 255, 'notnull' => false]);

        // The instant up to which cadence has been settled; next scan enumerates (cursor_at, now].
        $table->addColumn('cursor_at', 'datetime_immutable', ['notnull' => true]);

        // Optimistic-lock counter for concurrent advances across daemon nodes.
        $table->addColumn('cursor_version', 'integer', ['notnull' => true, 'default' => 1]);

        $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['schedule_id']);
        $table->addIndex(['tenant_id'], 'idx_scheduler_cursors_tenant');
    }
};
