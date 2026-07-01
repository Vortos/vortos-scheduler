<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Store\RunRetentionOverride;
use Vortos\Scheduler\Store\RunRetentionOverrideStoreInterface;

/**
 * Shared conformance test base for all RunRetentionOverrideStoreInterface drivers.
 *
 * Groups:
 *   A — basic CRUD
 *   B — upsert semantics
 *   C — findAll
 */
abstract class RunRetentionOverrideStoreConformanceTestCase extends TestCase
{
    abstract protected function createStore(): RunRetentionOverrideStoreInterface;

    private RunRetentionOverrideStoreInterface $store;

    protected function setUp(): void
    {
        $this->store = $this->createStore();
    }

    // ── Group A — basic CRUD ─────────────────────────────────────────────────

    public function test_save_and_find_round_trip(): void
    {
        $now      = new DateTimeImmutable('2026-07-01T10:00:00+00:00');
        $override = new RunRetentionOverride('tenant-1', 90, 'actor-1', $now);

        $this->store->save($override);
        $found = $this->store->find('tenant-1');

        self::assertNotNull($found);
        self::assertSame('tenant-1', $found->tenantId);
        self::assertSame(90, $found->retentionDays);
        self::assertSame('actor-1', $found->actorId);
    }

    public function test_find_missing_returns_null(): void
    {
        self::assertNull($this->store->find('no-such-tenant'));
    }

    public function test_remove_then_find_returns_null(): void
    {
        $this->store->save(new RunRetentionOverride('tenant-2', 60, 'a', new DateTimeImmutable()));
        $this->store->remove('tenant-2');
        self::assertNull($this->store->find('tenant-2'));
    }

    public function test_remove_missing_tenant_does_not_throw(): void
    {
        $this->store->remove('no-such-tenant');
        $this->addToAssertionCount(1);
    }

    public function test_zero_retention_days_is_legal_hold_and_round_trips(): void
    {
        $this->store->save(new RunRetentionOverride('tenant-hold', 0, 'compliance-officer', new DateTimeImmutable()));

        $found = $this->store->find('tenant-hold');

        self::assertNotNull($found);
        self::assertSame(0, $found->retentionDays);
        self::assertTrue($found->isExempt());
    }

    public function test_negative_retention_days_rejected_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RunRetentionOverride('tenant-x', -1, 'a', new DateTimeImmutable());
    }

    // ── Group B — upsert semantics ───────────────────────────────────────────

    public function test_save_twice_second_write_wins(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');
        $this->store->save(new RunRetentionOverride('tenant-3', 30, 'actor-1', $now));
        $this->store->save(new RunRetentionOverride('tenant-3', 90, 'actor-2', $now->modify('+1 hour')));

        $found = $this->store->find('tenant-3');
        self::assertSame(90, $found?->retentionDays);
        self::assertSame('actor-2', $found?->actorId);
    }

    // ── Group C — findAll ─────────────────────────────────────────────────────

    public function test_find_all_empty(): void
    {
        self::assertSame([], $this->store->findAll());
    }

    public function test_find_all_returns_every_override(): void
    {
        $now = new DateTimeImmutable('2026-07-01T10:00:00+00:00');
        $this->store->save(new RunRetentionOverride('tenant-a', 30, 'a', $now));
        $this->store->save(new RunRetentionOverride('tenant-b', 0, 'b', $now));

        $all = $this->store->findAll();

        self::assertCount(2, $all);
        $ids = array_map(fn (RunRetentionOverride $o) => $o->tenantId, $all);
        self::assertContains('tenant-a', $ids);
        self::assertContains('tenant-b', $ids);
    }
}
