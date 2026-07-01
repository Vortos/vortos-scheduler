<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Engine;

use DateTimeImmutable;
use Vortos\Scheduler\Schedule\Schedule;

/**
 * Pure scan engine — no I/O, no DBAL, no clock calls.
 *
 * Accepts a snapshot of active schedules + their last slot keys (provided
 * by the daemon from the run-store), and returns the set of fires due now
 * plus any dropped slots that exceeded the horizon.
 *
 * Shard pre-filter ($shardIndex / $shardCount) is a zero-cost hook for S5's
 * multi-node sharding (see SchedulerDaemon). Pass null/null for single-node.
 *
 * DueScan MUST remain dependency-free of any infrastructure (enforced by
 * SchedulerPurityArchTest).
 */
final class DueScan
{
    public function __construct(
        private readonly MisfireResolver $misfireResolver,
        private readonly int             $maxCatchupAgeSec = 86400,
    ) {}

    /**
     * @param  list<Schedule>          $schedules           Active schedules to evaluate
     * @param  array<string, string>   $lastSlotBySchedule  Map from scheduleId → lastSlotKey
     * @param  DateTimeImmutable       $now                 Monotonic wall-clock snapshot
     * @param  int|null                $shardIndex          0-based shard index (null = no shard)
     * @param  int|null                $shardCount          Total shard count (null = no shard)
     */
    public function compute(
        array             $schedules,
        array             $lastSlotBySchedule,
        DateTimeImmutable $now,
        ?int              $shardIndex = null,
        ?int              $shardCount = null,
    ): DueScanResult {
        $fires   = [];
        $dropped = [];

        foreach ($schedules as $schedule) {
            if (!$schedule->isActive()) {
                continue;
            }

            if (!$this->belongsToShard($schedule, $shardIndex, $shardCount)) {
                continue;
            }

            $lastSlotKey = $lastSlotBySchedule[$schedule->id->toString()] ?? null;

            $result = $this->misfireResolver->resolve(
                schedule:         $schedule,
                lastSlotKey:      $lastSlotKey,
                now:              $now,
                maxCatchupAgeSec: $this->maxCatchupAgeSec,
            );

            foreach ($result['fires'] as $fire) {
                $fires[] = $fire;
            }

            foreach ($result['dropped'] as $drop) {
                $dropped[] = $drop;
            }
        }

        return new DueScanResult(fires: $fires, dropped: $dropped);
    }

    private function belongsToShard(Schedule $schedule, ?int $shardIndex, ?int $shardCount): bool
    {
        if ($shardIndex === null || $shardCount === null || $shardCount <= 1) {
            return true;
        }

        // crc32 is unsigned in PHP and returns signed int — use abs() for portability.
        return abs(crc32($schedule->id->toString())) % $shardCount === $shardIndex;
    }
}
