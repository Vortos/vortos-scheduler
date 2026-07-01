<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string { return 'Scheduler'; }
    public function id(): string { return 'scheduler.create_run_retention_overrides'; }
    public function description(): string { return 'Per-tenant run retention overrides for auto-prune.'; }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('scheduler_run_retention_overrides'));
        $table->addColumn('tenant_id', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('retention_days', 'integer', ['notnull' => true]); // >= 0; 0 = legal hold (exempt)
        $table->addColumn('actor_id', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('updated_at', 'string', ['length' => 32, 'notnull' => true]);
        $table->setPrimaryKey(['tenant_id']);
    }
};
