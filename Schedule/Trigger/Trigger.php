<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Schedule\Trigger;

use DateTimeImmutable;

/**
 * The sole swap-seam for "when does this schedule fire?"
 *
 * Implementors must be pure: no I/O, no side effects, deterministic output.
 * nextRunAfter() MUST be monotonic: if $after advances, the returned instant must
 * be >= the previous result (or null). It must never go backwards.
 */
interface Trigger
{
    /**
     * The next fire instant strictly after $after.
     * Returns null when the schedule will never fire again (e.g. a past one-shot).
     */
    public function nextRunAfter(DateTimeImmutable $after): ?DateTimeImmutable;

    /**
     * Stable human-readable description for audit, preview, and doctor output.
     * Must not contain secrets or tenant data.
     */
    public function describe(): string;
}
