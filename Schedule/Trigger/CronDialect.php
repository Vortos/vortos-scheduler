<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule\Trigger;

enum CronDialect: string
{
    /** Standard 5-field: minute hour dom month dow */
    case FiveField = 'five_field';

    /**
     * 6-field with leading seconds: second minute hour dom month dow.
     * Opt-in only. Validated at construction and at scheduler:doctor.
     */
    case SixFieldSeconds = 'six_field_seconds';

    public function fieldCount(): int
    {
        return match ($this) {
            self::FiveField       => 5,
            self::SixFieldSeconds => 6,
        };
    }
}
