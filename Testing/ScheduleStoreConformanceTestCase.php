<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Testing;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Store\Exception\OptimisticLockException;
use Vortos\Scheduler\Store\Exception\ScheduleNameConflictException;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\Scheduler\Store\ScheduleStoreInterface;

/**
 * Shared conformance test base for all ScheduleStoreInterface drivers.
 *
 * Every method is final — the contract is what we test, not driver-specific behaviour.
 * Drivers override createStore() and provide database setup in setUp()/tearDown().
 *
 * Groups:
 *   A — CRUD round-trips
 *   B — Name uniqueness (per-tenant namespace)
 *   C — Tenant isolation
 *   D — Status filter (findActive)
 *   E — Optimistic concurrency
 */
abstract class ScheduleStoreConformanceTestCase extends TestCase
{
    private ScheduleStoreInterface $store;

    abstract protected function createStore(): ScheduleStoreInterface;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->createStore();
    }

    // ─────────────────────────────────────────────────────────────
    // Group A — CRUD round-trips
    // ─────────────────────────────────────────────────────────────

    final public function test_save_new_schedule_and_find_returns_it(): void
    {
        $schedule = $this->make(name: 'crud-new', tenantId: 'tenant-a');
        $this->store->save($schedule);

        $found = $this->store->find($schedule->id, 'tenant-a');

        self::assertNotNull($found);
        self::assertSame('crud-new', $found->name);
        self::assertSame('tenant-a', $found->tenantId);
    }

    final public function test_save_assigns_version_1_on_insert(): void
    {
        $schedule = $this->make(name: 'crud-version', tenantId: 'tenant-a');
        self::assertSame(0, $schedule->version);

        $this->store->save($schedule);

        $found = $this->store->find($schedule->id, 'tenant-a');
        self::assertNotNull($found);
        self::assertSame(1, $found->version);
    }

    final public function test_save_update_changes_status_and_increments_version(): void
    {
        $schedule = $this->make(name: 'crud-update', tenantId: 'tenant-a');
        $this->store->save($schedule);

        $v1 = $this->store->find($schedule->id, 'tenant-a');
        self::assertNotNull($v1);

        $paused = $v1->withStatus(ScheduleStatus::Paused);
        $this->store->save($paused);

        $v2 = $this->store->find($schedule->id, 'tenant-a');
        self::assertNotNull($v2);
        self::assertSame(ScheduleStatus::Paused, $v2->status);
        self::assertSame(2, $v2->version);
    }

    final public function test_delete_removes_schedule(): void
    {
        $schedule = $this->make(name: 'crud-delete', tenantId: 'tenant-a');
        $this->store->save($schedule);

        $this->store->delete($schedule->id, 'tenant-a');

        self::assertNull($this->store->find($schedule->id, 'tenant-a'));
    }

    final public function test_delete_nonexistent_throws_not_found(): void
    {
        $this->expectException(ScheduleNotFoundException::class);

        $this->store->delete(ScheduleId::generate(), 'tenant-a');
    }

    final public function test_find_returns_null_for_unknown_id(): void
    {
        self::assertNull($this->store->find(ScheduleId::generate(), 'tenant-a'));
    }

    final public function test_find_by_name_returns_schedule(): void
    {
        $schedule = $this->make(name: 'by-name-lookup', tenantId: 'tenant-b');
        $this->store->save($schedule);

        $found = $this->store->findByName('by-name-lookup', 'tenant-b');

        self::assertNotNull($found);
        self::assertTrue($schedule->id->equals($found->id));
    }

    final public function test_find_by_name_returns_null_for_unknown_name(): void
    {
        self::assertNull($this->store->findByName('does-not-exist', 'tenant-a'));
    }

    final public function test_system_schedule_save_and_find(): void
    {
        $schedule = $this->make(name: 'system-schedule', tenantId: null);
        $this->store->save($schedule);

        $found = $this->store->find($schedule->id, null);

        self::assertNotNull($found);
        self::assertNull($found->tenantId);
    }

    final public function test_all_scalar_fields_survive_round_trip(): void
    {
        $id       = ScheduleId::generate();
        $schedule = new Schedule(
            id:        $id,
            name:      'round-trip-fields',
            source:    ScheduleSource::Dynamic,
            trigger:   new IntervalTrigger(120),
            command:   new CommandSpec('App\\Job\\DoSomething', ['key' => 'val']),
            misfire:   MisfirePolicy::fireEachMissed(7),
            overlap:   OverlapPolicy::Queue,
            timezone:  new DateTimeZone('Australia/Sydney'),
            jitter:    new \Vortos\Scheduler\Schedule\Policy\Jitter(30),
            status:    ScheduleStatus::Paused,
            tenantId:  'tenant-c',
            sensitive: true,
            metadata:  ['env' => 'prod', 'team' => 'billing'],
        );

        $this->store->save($schedule);
        $found = $this->store->find($id, 'tenant-c');

        self::assertNotNull($found);
        self::assertTrue($id->equals($found->id));
        self::assertSame('round-trip-fields', $found->name);
        self::assertSame(ScheduleSource::Dynamic, $found->source);
        self::assertSame(OverlapPolicy::Queue, $found->overlap);
        self::assertSame('Australia/Sydney', $found->timezone->getName());
        self::assertSame(30, $found->jitter?->windowSeconds);
        self::assertSame(ScheduleStatus::Paused, $found->status);
        self::assertTrue($found->sensitive);
        self::assertSame(['env' => 'prod', 'team' => 'billing'], $found->metadata);
        self::assertSame('App\\Job\\DoSomething', $found->command->commandClass);
        self::assertSame(['key' => 'val'], $found->command->payload);
    }

    // ─────────────────────────────────────────────────────────────
    // Group B — Name uniqueness
    // ─────────────────────────────────────────────────────────────

    final public function test_duplicate_name_same_tenant_throws_conflict(): void
    {
        $this->store->save($this->make(name: 'dup-name', tenantId: 'tenant-a'));

        $this->expectException(ScheduleNameConflictException::class);

        $this->store->save($this->make(name: 'dup-name', tenantId: 'tenant-a'));
    }

    final public function test_same_name_different_tenants_allowed(): void
    {
        $this->store->save($this->make(name: 'shared-name', tenantId: 'tenant-a'));
        $this->store->save($this->make(name: 'shared-name', tenantId: 'tenant-b'));

        $this->addToAssertionCount(1);
    }

    final public function test_duplicate_system_name_throws_conflict(): void
    {
        $this->store->save($this->make(name: 'sys-dup', tenantId: null));

        $this->expectException(ScheduleNameConflictException::class);

        $this->store->save($this->make(name: 'sys-dup', tenantId: null));
    }

    final public function test_same_name_system_and_tenant_allowed(): void
    {
        $this->store->save($this->make(name: 'cross-scope', tenantId: null));
        $this->store->save($this->make(name: 'cross-scope', tenantId: 'tenant-a'));

        $this->addToAssertionCount(1);
    }

    // ─────────────────────────────────────────────────────────────
    // Group C — Tenant isolation
    // ─────────────────────────────────────────────────────────────

    final public function test_find_does_not_cross_tenant_boundary(): void
    {
        $schedule = $this->make(name: 'isolation-find', tenantId: 'tenant-a');
        $this->store->save($schedule);

        self::assertNull($this->store->find($schedule->id, 'tenant-b'));
    }

    final public function test_system_schedule_invisible_to_tenant_find(): void
    {
        $schedule = $this->make(name: 'sys-invisible', tenantId: null);
        $this->store->save($schedule);

        self::assertNull($this->store->find($schedule->id, 'tenant-a'));
    }

    final public function test_tenant_schedule_invisible_to_system_find(): void
    {
        $schedule = $this->make(name: 'tenant-invisible', tenantId: 'tenant-a');
        $this->store->save($schedule);

        self::assertNull($this->store->find($schedule->id, null));
    }

    final public function test_delete_wrong_tenant_throws_not_found(): void
    {
        $schedule = $this->make(name: 'del-isolation', tenantId: 'tenant-a');
        $this->store->save($schedule);

        $this->expectException(ScheduleNotFoundException::class);

        $this->store->delete($schedule->id, 'tenant-b');
    }

    final public function test_find_all_does_not_cross_tenant_boundary(): void
    {
        $this->store->save($this->make(name: 'all-a', tenantId: 'tenant-a'));
        $this->store->save($this->make(name: 'all-b', tenantId: 'tenant-b'));

        $all  = iterator_to_array($this->store->findAll('tenant-a'));
        $names = array_map(static fn (Schedule $s) => $s->name, $all);

        self::assertContains('all-a', $names);
        self::assertNotContains('all-b', $names);
    }

    final public function test_find_all_active_sees_all_tenants(): void
    {
        $this->store->save($this->make(name: 'fab-system',   tenantId: null,       status: ScheduleStatus::Active));
        $this->store->save($this->make(name: 'fab-tenant-a', tenantId: 'tenant-a', status: ScheduleStatus::Active));
        $this->store->save($this->make(name: 'fab-tenant-b', tenantId: 'tenant-b', status: ScheduleStatus::Active));

        $all   = iterator_to_array($this->store->findAllActive());
        $names = array_map(static fn (Schedule $s) => $s->name, $all);

        self::assertContains('fab-system',   $names);
        self::assertContains('fab-tenant-a', $names);
        self::assertContains('fab-tenant-b', $names);
    }

    // ─────────────────────────────────────────────────────────────
    // Group D — Status filter
    // ─────────────────────────────────────────────────────────────

    final public function test_find_active_returns_only_active_status(): void
    {
        $this->store->save($this->make(name: 'fa-active',   tenantId: 'tenant-d', status: ScheduleStatus::Active));
        $this->store->save($this->make(name: 'fa-paused',   tenantId: 'tenant-d', status: ScheduleStatus::Paused));
        $this->store->save($this->make(name: 'fa-disabled', tenantId: 'tenant-d', status: ScheduleStatus::Disabled));

        $active = iterator_to_array($this->store->findActive('tenant-d'));
        $names  = array_map(static fn (Schedule $s) => $s->name, $active);

        self::assertContains('fa-active', $names);
        self::assertNotContains('fa-paused', $names);
        self::assertNotContains('fa-disabled', $names);
    }

    final public function test_find_all_includes_all_statuses(): void
    {
        $this->store->save($this->make(name: 'fall-active',   tenantId: 'tenant-e', status: ScheduleStatus::Active));
        $this->store->save($this->make(name: 'fall-paused',   tenantId: 'tenant-e', status: ScheduleStatus::Paused));
        $this->store->save($this->make(name: 'fall-disabled', tenantId: 'tenant-e', status: ScheduleStatus::Disabled));

        $all   = iterator_to_array($this->store->findAll('tenant-e'));
        $names = array_map(static fn (Schedule $s) => $s->name, $all);

        self::assertContains('fall-active',   $names);
        self::assertContains('fall-paused',   $names);
        self::assertContains('fall-disabled', $names);
    }

    // ─────────────────────────────────────────────────────────────
    // Group E — Optimistic concurrency
    // ─────────────────────────────────────────────────────────────

    final public function test_stale_write_throws_optimistic_lock_exception(): void
    {
        $schedule = $this->make(name: 'opt-lock', tenantId: 'tenant-f');
        $this->store->save($schedule);

        // Two separate fetches — both at version 1
        $fetchA = $this->store->find($schedule->id, 'tenant-f');
        $fetchB = $this->store->find($schedule->id, 'tenant-f');
        self::assertNotNull($fetchA);
        self::assertNotNull($fetchB);
        self::assertSame(1, $fetchA->version);
        self::assertSame(1, $fetchB->version);

        // First write succeeds — version bumped to 2 in DB
        $this->store->save($fetchA->withStatus(ScheduleStatus::Paused));

        // Second write is now stale (still at version 1)
        $this->expectException(OptimisticLockException::class);
        $this->store->save($fetchB->withStatus(ScheduleStatus::Disabled));
    }

    final public function test_sequential_saves_increment_version_correctly(): void
    {
        $schedule = $this->make(name: 'opt-seq', tenantId: 'tenant-f');
        $this->store->save($schedule);

        $v1 = $this->store->find($schedule->id, 'tenant-f');
        self::assertNotNull($v1);
        self::assertSame(1, $v1->version);

        $this->store->save($v1->withStatus(ScheduleStatus::Paused));
        $v2 = $this->store->find($schedule->id, 'tenant-f');
        self::assertNotNull($v2);
        self::assertSame(2, $v2->version);

        $this->store->save($v2->withStatus(ScheduleStatus::Active));
        $v3 = $this->store->find($schedule->id, 'tenant-f');
        self::assertNotNull($v3);
        self::assertSame(3, $v3->version);
    }

    final public function test_update_nonexistent_throws_not_found(): void
    {
        // Simulate a fetched schedule (version=1) that was deleted concurrently
        $ghost = new Schedule(
            id:        ScheduleId::generate(),
            name:      'ghost-schedule',
            source:    ScheduleSource::Dynamic,
            trigger:   new IntervalTrigger(60),
            command:   new CommandSpec('App\\Command\\Ghost'),
            misfire:   MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::Skip,
            timezone:  new DateTimeZone('UTC'),
            jitter:    null,
            status:    ScheduleStatus::Active,
            tenantId:  'tenant-g',
            version:   1,  // looks like a fetched object
        );

        $this->expectException(ScheduleNotFoundException::class);
        $this->store->save($ghost);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function make(
        string         $name,
        ?string        $tenantId,
        ScheduleStatus $status = ScheduleStatus::Active,
    ): Schedule {
        return new Schedule(
            id:       ScheduleId::generate(),
            name:     $name,
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(60),
            command:  new CommandSpec('App\\Command\\TestCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   $status,
            tenantId: $tenantId,
        );
    }
}
