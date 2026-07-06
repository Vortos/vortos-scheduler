<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

/**
 * R7-4 / SCHED-1: capability-aware fire-queue with a requeue safety net.
 *
 * Adds the bookkeeping a consumer needs to leave a fire it cannot run for a capable consumer,
 * rather than hard-failing it (the production failure where a stale blue/green standby drained a
 * newer command off the shared queue and marked it `failed`).
 *
 *  - `attempts`     — how many times this fire has been requeued (bounded by max_attempts).
 *  - `available_at` — visibility timeout: a requeued row is invisible to the claim scan until now.
 *  - `last_error`   — the transient reason for the last requeue (distinct from terminal
 *                     `failure_reason`).
 *
 * Lifecycle vocabulary gains two terminal-ish states in addition to pending/processing/dispatched/
 * failed: a row cycles pending → processing → (requeued back to) pending, and after max_attempts a
 * capability gap becomes `dead_letter` (surfaced by SchedulerDoctor) instead of a silent drop.
 *
 * Alter-style provider (guarded hasTable) — publishes correctly via the cumulative-schema diff
 * (R7-1).
 */
return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Scheduler';
    }

    public function id(): string
    {
        return 'scheduler.fire_queue_requeue_columns';
    }

    public function description(): string
    {
        return 'Add requeue/backoff bookkeeping to the scheduler fire-queue (R7-4)';
    }

    public function define(Schema $schema): void
    {
        if (!$schema->hasTable($this->t('scheduler_fire_queue'))) {
            return;
        }

        $table = $schema->getTable($this->t('scheduler_fire_queue'));

        if (!$table->hasColumn('attempts')) {
            $table->addColumn('attempts', 'integer', ['notnull' => true, 'default' => 0]);
        }

        if (!$table->hasColumn('available_at')) {
            $table->addColumn('available_at', 'datetime_immutable', ['notnull' => false]);
        }

        if (!$table->hasColumn('last_error')) {
            $table->addColumn('last_error', 'text', ['notnull' => false]);
        }

        // Claim scan: visible pending rows in schedule order, honouring the visibility timeout.
        if (!$table->hasIndex('idx_sched_fq_claim')) {
            $table->addIndex(['status', 'available_at', 'created_at'], 'idx_sched_fq_claim');
        }
    }
};
