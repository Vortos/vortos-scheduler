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
        return 'scheduler.create_leases';
    }

    public function description(): string
    {
        return 'Create scheduler lease table for SqlLeaseStore distributed locking (Block S2)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('scheduler_leases'));

        $table->addColumn('lease_key',   'string',             ['length' => 255, 'notnull' => true]);
        $table->addColumn('owner_token', 'string',             ['length' => 64,  'notnull' => true]);
        $table->addColumn('acquired_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('expires_at',  'datetime_immutable', ['notnull' => true]);
        $table->addColumn('renewed_at',  'datetime_immutable', ['notnull' => false]);

        $table->setPrimaryKey(['lease_key']);
        $table->addIndex(['expires_at'], 'idx_scheduler_leases_expires_at');
    }
};
