<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Engine\SchedulerDaemon;
use Vortos\Scheduler\Schedule\ScheduleId;

/**
 * Pure unit tests for SchedulerDaemon static helpers.
 * No infrastructure required — all assertions are on pure deterministic logic.
 */
final class SchedulerDaemonStaticTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────
    // leaseKeyForShard
    // ─────────────────────────────────────────────────────────────

    public function test_lease_key_for_shard_zero(): void
    {
        self::assertSame('scheduler:leader:0', SchedulerDaemon::leaseKeyForShard(0));
    }

    public function test_lease_key_for_shard_five(): void
    {
        self::assertSame('scheduler:leader:5', SchedulerDaemon::leaseKeyForShard(5));
    }

    public function test_lease_key_for_shard_large_index(): void
    {
        self::assertSame('scheduler:leader:99', SchedulerDaemon::leaseKeyForShard(99));
    }

    public function test_lease_key_format_is_consistent(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::assertSame("scheduler:leader:{$i}", SchedulerDaemon::leaseKeyForShard($i));
        }
    }

    // ─────────────────────────────────────────────────────────────
    // shardIndexFor — single-shard always 0
    // ─────────────────────────────────────────────────────────────

    public function test_shard_index_for_single_shard_is_always_zero(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $id = ScheduleId::generate();
            self::assertSame(0, SchedulerDaemon::shardIndexFor($id, 1));
        }
    }

    // ─────────────────────────────────────────────────────────────
    // shardIndexFor — in range [0, shardCount)
    // ─────────────────────────────────────────────────────────────

    public function test_shard_index_for_two_shards_in_range(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $idx = SchedulerDaemon::shardIndexFor(ScheduleId::generate(), 2);
            self::assertGreaterThanOrEqual(0, $idx);
            self::assertLessThan(2, $idx);
        }
    }

    public function test_shard_index_for_four_shards_in_range(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $idx = SchedulerDaemon::shardIndexFor(ScheduleId::generate(), 4);
            self::assertGreaterThanOrEqual(0, $idx);
            self::assertLessThan(4, $idx);
        }
    }

    public function test_shard_index_for_sixteen_shards_in_range(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $idx = SchedulerDaemon::shardIndexFor(ScheduleId::generate(), 16);
            self::assertGreaterThanOrEqual(0, $idx);
            self::assertLessThan(16, $idx);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // shardIndexFor — determinism
    // ─────────────────────────────────────────────────────────────

    public function test_shard_index_for_is_deterministic(): void
    {
        $id = ScheduleId::generate();

        $first  = SchedulerDaemon::shardIndexFor($id, 4);
        $second = SchedulerDaemon::shardIndexFor($id, 4);
        $third  = SchedulerDaemon::shardIndexFor($id, 4);

        self::assertSame($first, $second);
        self::assertSame($first, $third);
    }

    public function test_shard_index_for_same_id_different_shard_counts(): void
    {
        $id = ScheduleId::generate();

        // With shardCount=1, must be 0.
        self::assertSame(0, SchedulerDaemon::shardIndexFor($id, 1));

        // With other shard counts, must still be in range.
        foreach ([2, 3, 4, 8, 10] as $n) {
            $idx = SchedulerDaemon::shardIndexFor($id, $n);
            self::assertGreaterThanOrEqual(0, $idx, "shardIndexFor with shardCount={$n} must be >= 0");
            self::assertLessThan($n, $idx, "shardIndexFor with shardCount={$n} must be < {$n}");
        }
    }

    // ─────────────────────────────────────────────────────────────
    // shardIndexFor — abs() guard against negative crc32
    // ─────────────────────────────────────────────────────────────

    public function test_shard_index_for_never_returns_negative(): void
    {
        // PHP crc32() can return negative values on 64-bit systems.
        // Generate many IDs to probe the signed-int boundary.
        for ($i = 0; $i < 200; $i++) {
            $idx = SchedulerDaemon::shardIndexFor(ScheduleId::generate(), 7);
            self::assertGreaterThanOrEqual(0, $idx, 'shardIndexFor must never return a negative index');
        }
    }

    public function test_shard_index_for_distributes_across_shards(): void
    {
        // Statistical distribution: with 200 random IDs and 4 shards,
        // all 4 buckets should receive at least 1 hit.
        $counts = array_fill(0, 4, 0);

        for ($i = 0; $i < 200; $i++) {
            $idx = SchedulerDaemon::shardIndexFor(ScheduleId::generate(), 4);
            $counts[$idx]++;
        }

        foreach ($counts as $shard => $count) {
            self::assertGreaterThan(0, $count, "Shard {$shard} received 0 assignments (distribution failure)");
        }
    }
}
