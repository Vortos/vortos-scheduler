<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

/**
 * FB-64: a lease on every fire-queue claim, so a fire whose consumer died mid-dispatch can be found.
 *
 * `claimed_at` is stamped when a consumer moves a row to `processing`. A row still `processing` past
 * `fire_lease_sec` is presumed abandoned and FireQueueConsumer requeues it — or fails it, once older
 * than `fire_stranded_max_age_sec`. Before this, a consumer that exited on a fatal between claim and
 * completion left its rows in `processing` permanently: no retry, no terminal state for the prune to
 * collect, and no signal anywhere.
 *
 * Expand-only: one nullable column and one index. Rows claimed before the column existed carry NULL,
 * and the consumer falls back to `created_at` for them.
 */
return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Scheduler';
    }

    public function id(): string
    {
        return 'scheduler.fire_queue_claim_lease';
    }

    public function description(): string
    {
        return 'Stamp fire-queue claims so abandoned processing rows can be reclaimed (FB-64)';
    }

    public function define(Schema $schema): void
    {
        if (!$schema->hasTable($this->t('scheduler_fire_queue'))) {
            return;
        }

        $table = $schema->getTable($this->t('scheduler_fire_queue'));

        if (!$table->hasColumn('claimed_at')) {
            $table->addColumn('claimed_at', 'datetime_immutable', ['notnull' => false]);
        }

        // Stranded-row probe: processing rows by claim age.
        if (!$table->hasIndex('idx_sched_fq_processing_claimed')) {
            $table->addIndex(['status', 'claimed_at'], 'idx_sched_fq_processing_claimed');
        }
    }
};
