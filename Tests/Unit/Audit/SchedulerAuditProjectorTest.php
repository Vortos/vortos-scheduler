<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Audit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Audit\SchedulerAuditEntry;
use Vortos\Scheduler\Audit\SchedulerAuditEvent;
use Vortos\Scheduler\Audit\SchedulerAuditProjector;
use Vortos\Scheduler\Audit\SchedulerAuditRepositoryInterface;
use Vortos\Scheduler\Engine\DroppedSlotRecord;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Tests\Unit\Security\Support\StubAllowlistedCommand;

final class SchedulerAuditProjectorTest extends TestCase
{
    private const HMAC_KEY = 'test-hmac-key-for-audit-projector';

    private InMemoryAuditRepository $repository;
    private SchedulerAuditProjector $projector;

    protected function setUp(): void
    {
        $this->repository = new InMemoryAuditRepository();
        $this->projector  = new SchedulerAuditProjector(
            $this->repository,
            self::HMAC_KEY,
            'testing',
        );
    }

    // ── Fire events ──────────────────────────────────────────────────────────

    public function test_on_fire_dispatched_appends_correct_event_type(): void
    {
        $fire = $this->makeFire();
        $this->projector->onFireDispatched($fire, 250, false);

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::FireDispatched->value, $entry->eventType);
        self::assertSame($fire->scheduleId->toString(), $entry->scheduleId);
        self::assertSame($fire->slot, $entry->slot);
        self::assertSame(250, $entry->data['lag_ms']);
        self::assertFalse($entry->data['jitter_applied']);
        self::assertSame(1, $entry->data['attempt']);
        self::assertSame('system', $entry->actorId);
    }

    public function test_on_fire_dispatched_with_jitter(): void
    {
        $this->projector->onFireDispatched($this->makeFire(), 100, true);
        self::assertTrue($this->lastEntry()->data['jitter_applied']);
    }

    public function test_on_fire_skipped_overlap(): void
    {
        $this->projector->onFireSkippedOverlap($this->makeFire(), 'prior-run-id', 'dispatched');

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::FireSkippedOverlap->value, $entry->eventType);
        self::assertSame('prior-run-id', $entry->data['prior_run_id']);
        self::assertSame('dispatched', $entry->data['prior_run_state']);
    }

    public function test_on_fire_misfired(): void
    {
        $this->projector->onFireMisfired($this->makeFire(), MisfirePolicy::fireEachMissed(), 3, 1);

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::FireMisfired->value, $entry->eventType);
        self::assertSame('fire_each_missed', $entry->data['policy_applied']);
        self::assertSame(3, $entry->data['slots_fired']);
        self::assertSame(1, $entry->data['slots_dropped']);
    }

    public function test_on_slot_dropped(): void
    {
        $drop = new DroppedSlotRecord(
            $this->makeScheduleId(),
            'tenant-1',
            new DateTimeImmutable('2026-07-01T05:00:00+00:00'),
            'beyond_horizon',
        );

        $this->projector->onSlotDropped($drop);

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::FireDropped->value, $entry->eventType);
        self::assertSame('beyond_horizon', $entry->data['reason']);
        self::assertSame($drop->scheduleId->toString(), $entry->scheduleId);
    }

    // ── Leader events ────────────────────────────────────────────────────────

    public function test_on_leader_acquired(): void
    {
        $this->projector->onLeaderAcquired(0);

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::LeaderAcquired->value, $entry->eventType);
        self::assertSame(0, $entry->shardIndex);
        self::assertSame('system', $entry->actorId);
        self::assertNull($entry->scheduleId);
        self::assertNull($entry->slot);
        self::assertArrayHasKey('node_id', $entry->data);
    }

    public function test_on_leader_lost(): void
    {
        $this->projector->onLeaderLost(2);

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::LeaderLost->value, $entry->eventType);
        self::assertSame(2, $entry->shardIndex);
    }

    // ── Schedule mutation events ─────────────────────────────────────────────

    public function test_on_schedule_created(): void
    {
        $schedule = $this->makeSchedule();
        $this->projector->onScheduleCreated($schedule, 'user-1');

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::ScheduleCreated->value, $entry->eventType);
        self::assertSame('user-1', $entry->actorId);
        self::assertSame($schedule->name, $entry->data['name']);
        self::assertArrayHasKey('trigger_desc', $entry->data);
        self::assertArrayHasKey('misfire_policy', $entry->data);
        self::assertSame('dynamic', $entry->data['source']);
    }

    public function test_on_schedule_updated_includes_reason(): void
    {
        $this->projector->onScheduleUpdated($this->makeSchedule(), 'user-2', 'Changed interval');

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::ScheduleUpdated->value, $entry->eventType);
        self::assertSame('Changed interval', $entry->data['reason']);
    }

    public function test_on_schedule_paused(): void
    {
        $this->projector->onSchedulePaused($this->makeSchedule(), 'admin', 'Maintenance window');

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::SchedulePaused->value, $entry->eventType);
        self::assertSame('Maintenance window', $entry->data['reason']);
    }

    public function test_on_schedule_resumed(): void
    {
        $this->projector->onScheduleResumed($this->makeSchedule(), 'admin');

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::ScheduleResumed->value, $entry->eventType);
    }

    public function test_on_schedule_deleted(): void
    {
        $this->projector->onScheduleDeleted($this->makeSchedule(), 'admin', 'Deprecated');

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::ScheduleDeleted->value, $entry->eventType);
        self::assertSame('Deprecated', $entry->data['reason']);
    }

    public function test_on_schedule_approved(): void
    {
        $schedule = $this->makeSchedule();
        $this->projector->onScheduleApproved($schedule, 'approver-1', 'original-actor');

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::ScheduleApproved->value, $entry->eventType);
        self::assertSame('approver-1', $entry->data['approver_id']);
        self::assertSame('original-actor', $entry->data['original_actor_id']);
    }

    // ── Run retention / auto-prune ───────────────────────────────────────────

    public function test_on_runs_pruned_records_deleted_count_and_cutoff(): void
    {
        $cutoff = new DateTimeImmutable('2026-06-01T00:00:00+00:00');
        $this->projector->onRunsPruned('system', 'tenant-1', 42, $cutoff, false);

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::RunsPruned->value, $entry->eventType);
        self::assertSame('system', $entry->actorId);
        self::assertSame('tenant-1', $entry->tenantId);
        self::assertSame(42, $entry->data['deleted_count']);
        self::assertFalse($entry->data['truncated']);
        self::assertTrue($entry->data['resolved']);
    }

    public function test_on_runs_pruned_manual_bypass_marks_unresolved(): void
    {
        $this->projector->onRunsPruned('operator-1', null, 5, new DateTimeImmutable(), false, resolved: false);

        self::assertFalse($this->lastEntry()->data['resolved']);
    }

    public function test_on_runs_pruned_never_throws_on_repository_failure(): void
    {
        $throwing = new class implements SchedulerAuditRepositoryInterface {
            public function appendNext(string $chainKey, callable $builder): SchedulerAuditEntry
            {
                throw new \RuntimeException('DB is down');
            }

            public function findByChainKey(string $chainKey, int $limit = 1000): array { return []; }
            public function findBySchedule(string $scheduleId, ?string $tenantId = null, int $limit = 500): array { return []; }
            public function findByTenant(?string $tenantId, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 1000): array { return []; }
            public function stream(?string $chainKey = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): \Generator { return; yield; }
        };

        $projector = new SchedulerAuditProjector($throwing, self::HMAC_KEY, 'testing');
        $projector->onRunsPruned('system', null, 1, new DateTimeImmutable(), false);

        $this->addToAssertionCount(1);
    }

    public function test_on_retention_override_set_records_days_and_reason(): void
    {
        $this->projector->onRetentionOverrideSet('tenant-1', 90, 'compliance-officer', 'contractual retention');

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::RetentionOverrideSet->value, $entry->eventType);
        self::assertSame('compliance-officer', $entry->actorId);
        self::assertSame('tenant-1', $entry->tenantId);
        self::assertSame(90, $entry->data['retention_days']);
        self::assertSame('contractual retention', $entry->data['reason']);
    }

    public function test_on_retention_override_set_propagates_repository_exception(): void
    {
        $throwing = new class implements SchedulerAuditRepositoryInterface {
            public function appendNext(string $chainKey, callable $builder): SchedulerAuditEntry
            {
                throw new \RuntimeException('DB is down');
            }

            public function findByChainKey(string $chainKey, int $limit = 1000): array { return []; }
            public function findBySchedule(string $scheduleId, ?string $tenantId = null, int $limit = 500): array { return []; }
            public function findByTenant(?string $tenantId, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 1000): array { return []; }
            public function stream(?string $chainKey = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): \Generator { return; yield; }
        };

        $projector = new SchedulerAuditProjector($throwing, self::HMAC_KEY, 'testing');

        $this->expectException(\RuntimeException::class);
        $projector->onRetentionOverrideSet('tenant-1', 0, 'admin', null);
    }

    public function test_on_retention_override_removed(): void
    {
        $this->projector->onRetentionOverrideRemoved('tenant-1', 'admin');

        $entry = $this->lastEntry();
        self::assertSame(SchedulerAuditEvent::RetentionOverrideRemoved->value, $entry->eventType);
        self::assertSame('admin', $entry->actorId);
        self::assertSame('tenant-1', $entry->tenantId);
    }

    // ── Chain key resolution ─────────────────────────────────────────────────

    public function test_chain_key_uses_tenant_id_when_set(): void
    {
        $schedule = $this->makeSchedule(tenantId: 'tenant-99');
        $this->projector->onScheduleCreated($schedule, 'user-1');

        self::assertSame('scheduler:tenant-99:testing', $this->lastEntry()->chainKey);
    }

    public function test_chain_key_uses_system_for_null_tenant(): void
    {
        $schedule = $this->makeSchedule(tenantId: null);
        $this->projector->onScheduleCreated($schedule, 'user-1');

        self::assertSame('scheduler:system:testing', $this->lastEntry()->chainKey);
    }

    public function test_leader_events_use_system_chain_key(): void
    {
        $this->projector->onLeaderAcquired(0);
        self::assertSame('scheduler:system:testing', $this->lastEntry()->chainKey);
    }

    // ── Safety: fire events must not throw ──────────────────────────────────

    public function test_fire_event_catches_repository_exception(): void
    {
        $throwing = new class implements SchedulerAuditRepositoryInterface {
            public function appendNext(string $chainKey, callable $builder): SchedulerAuditEntry
            {
                throw new \RuntimeException('DB is down');
            }

            public function findByChainKey(string $chainKey, int $limit = 1000): array { return []; }
            public function findBySchedule(string $scheduleId, ?string $tenantId = null, int $limit = 500): array { return []; }
            public function findByTenant(?string $tenantId, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 1000): array { return []; }
            public function stream(?string $chainKey = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): \Generator { return; yield; }
        };

        $projector = new SchedulerAuditProjector($throwing, self::HMAC_KEY, 'testing');

        // Must NOT throw
        $projector->onFireDispatched($this->makeFire(), 100, false);
        $projector->onLeaderAcquired(0);
        $projector->onSlotDropped(new DroppedSlotRecord(
            ScheduleId::fromString('01920000-0000-7000-8000-000000000002'), null, new DateTimeImmutable(), 'beyond_horizon',
        ));

        $this->addToAssertionCount(1);
    }

    public function test_mutation_event_propagates_repository_exception(): void
    {
        $throwing = new class implements SchedulerAuditRepositoryInterface {
            public function appendNext(string $chainKey, callable $builder): SchedulerAuditEntry
            {
                throw new \RuntimeException('DB is down');
            }

            public function findByChainKey(string $chainKey, int $limit = 1000): array { return []; }
            public function findBySchedule(string $scheduleId, ?string $tenantId = null, int $limit = 500): array { return []; }
            public function findByTenant(?string $tenantId, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 1000): array { return []; }
            public function stream(?string $chainKey = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): \Generator { return; yield; }
        };

        $projector = new SchedulerAuditProjector($throwing, self::HMAC_KEY, 'testing');

        $this->expectException(\RuntimeException::class);
        $projector->onScheduleCreated($this->makeSchedule(), 'user-1');
    }

    // ── Data never contains HMAC key ─────────────────────────────────────────

    public function test_data_payload_never_contains_hmac_key(): void
    {
        $this->projector->onFireDispatched($this->makeFire(), 100, false);

        $entry = $this->lastEntry();
        $json  = json_encode($entry->data);

        self::assertStringNotContainsString(self::HMAC_KEY, (string) $json);
        self::assertStringNotContainsString(self::HMAC_KEY, $entry->signature);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeScheduleId(): ScheduleId
    {
        return ScheduleId::fromString('01920000-0000-7000-8000-000000000001');
    }

    private function makeFire(string $tenantId = 'tenant-1'): ScheduledFire
    {
        $id = $this->makeScheduleId();

        return new ScheduledFire(
            scheduleId:   $id,
            tenantId:     $tenantId,
            slot:         $id->toString() . ':2026-07-01T02:00:00+00:00',
            scheduledFor: new DateTimeImmutable('2026-07-01T02:00:00', new DateTimeZone('UTC')),
            attempt:      1,
        );
    }

    private function makeSchedule(string $name = 'nightly-report', ?string $tenantId = 'tenant-1'): Schedule
    {
        return new Schedule(
            id:       $this->makeScheduleId(),
            name:     $name,
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec(StubAllowlistedCommand::class, []),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: $tenantId,
        );
    }

    private function lastEntry(): SchedulerAuditEntry
    {
        $all = $this->repository->all();
        self::assertNotEmpty($all, 'Expected at least one audit entry');

        return end($all);
    }
}

// ── In-memory stub repository ─────────────────────────────────────────────────

final class InMemoryAuditRepository implements SchedulerAuditRepositoryInterface
{
    /** @var list<SchedulerAuditEntry> */
    private array $entries = [];

    public function appendNext(string $chainKey, callable $builder): SchedulerAuditEntry
    {
        $sequence = count(array_filter($this->entries, fn (SchedulerAuditEntry $e) => $e->chainKey === $chainKey));
        $prevHash = $sequence === 0
            ? \Vortos\Observability\Audit\AuditHashChain::GENESIS_HASH
            : end(array_filter($this->entries, fn (SchedulerAuditEntry $e) => $e->chainKey === $chainKey))->contentHash;

        $entry           = $builder($sequence, $prevHash);
        $this->entries[] = $entry;

        return $entry;
    }

    public function findByChainKey(string $chainKey, int $limit = 1000): array
    {
        return array_values(array_filter($this->entries, fn (SchedulerAuditEntry $e) => $e->chainKey === $chainKey));
    }

    public function findBySchedule(string $scheduleId, ?string $tenantId = null, int $limit = 500): array
    {
        return array_values(array_filter($this->entries, fn (SchedulerAuditEntry $e) => $e->scheduleId === $scheduleId));
    }

    public function findByTenant(?string $tenantId, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, int $limit = 1000): array
    {
        return array_values(array_filter($this->entries, fn (SchedulerAuditEntry $e) => $e->tenantId === $tenantId));
    }

    public function stream(?string $chainKey = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): \Generator
    {
        foreach ($this->entries as $entry) {
            if ($chainKey === null || $entry->chainKey === $chainKey) {
                yield $entry;
            }
        }
    }

    /** @return list<SchedulerAuditEntry> */
    public function all(): array
    {
        return $this->entries;
    }
}
