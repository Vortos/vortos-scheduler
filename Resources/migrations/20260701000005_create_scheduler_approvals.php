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
        return 'scheduler.create_approvals';
    }

    public function description(): string
    {
        return 'Create 4-eyes approval table for sensitive schedule governance (Block S7)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('scheduler_approvals'));

        // Primary key: UUIDv7 (time-ordered for B-tree efficiency)
        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);

        // The schedule being governed
        $table->addColumn('schedule_id', 'string', ['length' => 36, 'notnull' => true]);

        // The gated action: 'activate' | 'run-now'
        $table->addColumn('action', 'string', ['length' => 20, 'notnull' => true]);

        // Lifecycle: 'pending' | 'approved' | 'rejected' | 'expired'
        $table->addColumn('status', 'string', ['length' => 20, 'notnull' => true, 'default' => 'pending']);

        // Requester identity
        $table->addColumn('requested_by', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('requested_at', 'datetime_immutable', ['notnull' => true]);

        // TTL: request expires at this time if not resolved
        $table->addColumn('expires_at', 'datetime_immutable', ['notnull' => true]);

        // Optional human-readable justification
        $table->addColumn('reason', 'text', ['notnull' => false]);

        // Resolver identity (null while pending)
        $table->addColumn('resolved_by', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('resolved_at', 'datetime_immutable', ['notnull' => false]);

        // ── Constraints ─────────────────────────────────────────────────────────
        $table->setPrimaryKey(['id']);

        // ── Indexes ─────────────────────────────────────────────────────────────

        // findPending() lookup — the primary hot path for gate enforcement
        $table->addIndex(
            ['schedule_id', 'action', 'status'],
            'idx_scheduler_approvals_pending',
        );

        // findBySchedule() — audit history per schedule
        $table->addIndex(
            ['schedule_id', 'requested_at'],
            'idx_scheduler_approvals_history',
        );

        // expireStaleBefore() — TTL sweeper range scan
        $table->addIndex(
            ['status', 'expires_at'],
            'idx_scheduler_approvals_expire',
        );
    }
};
