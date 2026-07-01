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
        return 'scheduler.create_static_overrides';
    }

    public function description(): string
    {
        return 'Create runtime pause overrides for static schedules (Block S9)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('scheduler_static_overrides'));
        $table->addColumn('schedule_id', 'string', ['length' => 36,  'notnull' => true]);
        $table->addColumn('status',      'string', ['length' => 20,  'notnull' => true]);
        $table->addColumn('actor_id',    'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('reason',      'text',   ['notnull' => false]);
        $table->addColumn('updated_at',  'string', ['length' => 32,  'notnull' => true]);
        $table->setPrimaryKey(['schedule_id']);
    }
};
