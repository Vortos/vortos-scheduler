<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

use Vortos\OpsKit\Gate\GateDisposition;

/**
 * The scheduler doctor's checks, each with the gate disposition it was designed under.
 *
 * A finding is constructed from one of these cases, never from a free-form id, so a new check
 * cannot exist without a case here — and the exhaustive match in {@see disposition()} means it
 * cannot exist without deciding whether it may stop a release. See {@see GateDisposition} for the
 * rule and for the two deadlocks (C14, C15) that the old `gatesDeploy = true` default produced.
 */
enum SchedulerDoctorCheck: string
{
    case C1  = 'C1';
    case C2  = 'C2';
    case C3  = 'C3';
    case C4  = 'C4';
    case C5  = 'C5';
    case C6  = 'C6';
    case C7  = 'C7';
    case C8  = 'C8';
    case C9  = 'C9';
    case C10 = 'C10';
    case C11 = 'C11';
    case C12 = 'C12';
    case C13 = 'C13';
    case C14 = 'C14';
    case C15 = 'C15';

    public function title(): string
    {
        return match ($this) {
            self::C1  => 'Trigger expressions are valid',
            self::C2  => 'No schedule name or id collisions',
            self::C3  => 'Scheduled command classes are allowlisted',
            self::C4  => 'Lease driver is reachable',
            self::C5  => 'Scheduler tables are migrated',
            self::C6  => 'Sensitive schedules carry approvals',
            self::C7  => 'Sensitive schedules declare a misfire policy',
            self::C8  => 'Catch-up bounds are valid',
            self::C9  => 'Shard configuration is valid',
            self::C10 => 'Run retention is running',
            self::C11 => 'Fire-queue consumer is draining',
            self::C12 => 'No dead-lettered fires',
            self::C13 => 'Dead-man detector is wired',
            self::C14 => 'No active schedule is overdue',
            self::C15 => 'No fire abandoned mid-dispatch',
        };
    }

    public function disposition(): GateDisposition
    {
        return match ($this) {
            // Configuration and capability: the release itself is what is wrong.
            self::C1, self::C2, self::C3, self::C5, self::C6, self::C7, self::C8, self::C9,
            self::C13 => GateDisposition::Blocking,

            // A lease driver that cannot be reached is either the release's own misconfigured
            // connection or a provider outage, and the check cannot tell which — so it fails closed.
            self::C4 => GateDisposition::Blocking,

            // Live runtime state. The running consumer, pruner or daemon is what produces it, and a
            // new release is very often what clears it: gating on it refuses the cure.
            self::C10, self::C11, self::C12, self::C14, self::C15 => GateDisposition::Advisory,
        };
    }
}
