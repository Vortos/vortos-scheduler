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
        return 'scheduler.cursor_first_seen_at';
    }

    public function description(): string
    {
        return 'Record when the daemon first observed each schedule, so an overdue check can tell '
            . 'a newly registered schedule apart from one that has stopped firing';
    }

    public function define(Schema $schema): void
    {
        // Alter-style provider: guarded hasTable/hasColumn so it publishes correctly through the
        // cumulative-schema diff and is a no-op when evaluated against a fresh schema that has not
        // reached the create-cursors provider yet. Without the guard this throws during publish —
        // caught by the framework's own "publish safe against a fresh schema" conformance test.
        if (!$schema->hasTable($this->t('scheduler_cursors'))) {
            return;
        }

        $table = $schema->getTable($this->t('scheduler_cursors'));

        if ($table->hasColumn('first_seen_at')) {
            return;
        }

        // WHY THIS COLUMN EXISTS
        //
        // scheduler:doctor C14 reports schedules that are not dispatching. Without a "first seen"
        // instant it cannot distinguish two very different states that look identical in the run
        // ledger — both have zero rows:
        //
        //   (a) a daily schedule registered ninety seconds ago, whose first slot is a day away;
        //   (b) a daily schedule that has been dead for a week.
        //
        // Reporting (a) as overdue is a false alarm on every single deployment that introduces a
        // schedule, which is exactly how a check trains people to ignore it. Inferring the answer
        // from cursor_at does not work either: absolute (cron) triggers advance their cursor to
        // `now` on every tick, so the anchor carries no age.
        //
        // NULLABLE because NULL is the truthful value, not because it is convenient to add.
        // Rows written before this column existed genuinely have no recorded first-seen instant,
        // and inventing one — backfilling `now`, or the migration timestamp — would be a lie that
        // C14 then reasons from: every pre-existing schedule would look brand new and be excused
        // from the overdue check for a full tolerance window. NULL says "unknown", C14 declines to
        // judge on unknown, and the state becomes known the moment that schedule's cursor is next
        // written. Correctness first; the fact that a nullable add is also metadata-only is a
        // consequence, not the reason.
        $table->addColumn('first_seen_at', 'datetime_immutable', ['notnull' => false]);
    }
};
