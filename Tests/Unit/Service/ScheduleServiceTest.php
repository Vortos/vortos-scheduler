<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\ScheduleResolver;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Service\ScheduleService;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\Scheduler\Store\ScheduleStatusOverride;
use Vortos\Scheduler\Testing\FakeFireDispatcherPort;
use Vortos\Scheduler\Testing\FakeSchedulePolicy;
use Vortos\Scheduler\Testing\FakeUserIdentity;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;
use Vortos\Scheduler\Tests\Unit\Service\Support\FixedStaticScheduleDefinition;
use Vortos\Scheduler\Tests\Unit\Service\Support\SensitiveStaticScheduleDefinition;

/**
 * @covers \Vortos\Scheduler\Service\ScheduleService
 */
final class ScheduleServiceTest extends TestCase
{
    private InMemoryScheduleStore $dynamicStore;
    private InMemoryScheduleStatusOverrideStore $overrideStore;
    private FakeSchedulePolicy $policy;
    private MutableClock $clock;
    private FakeFireDispatcherPort $dispatcher;
    private FakeUserIdentity $actor;

    protected function setUp(): void
    {
        $this->dynamicStore  = new InMemoryScheduleStore();
        $this->overrideStore = new InMemoryScheduleStatusOverrideStore();
        $this->policy        = new FakeSchedulePolicy();
        $this->clock         = new MutableClock(new DateTimeImmutable('2026-07-01T12:00:00+00:00'));
        $this->dispatcher    = new FakeFireDispatcherPort();
        $this->actor         = new FakeUserIdentity('operator-1');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeService(?StaticScheduleRegistry $registry = null): ScheduleService
    {
        return new ScheduleService(
            staticRegistry: $registry ?? new StaticScheduleRegistry([]),
            dynamicStore:   $this->dynamicStore,
            overrideStore:  $this->overrideStore,
            policy:         $this->policy,
            clock:          $this->clock,
            fireDispatcher: $this->dispatcher,
        );
    }

    private function makeDynamic(
        string $name = 'test-schedule',
        ?string $tenantId = 'tenant-1',
        ScheduleStatus $status = ScheduleStatus::Active,
        bool $sensitive = false,
    ): Schedule {
        return new Schedule(
            id:        ScheduleId::generate(),
            name:      $name,
            source:    ScheduleSource::Dynamic,
            trigger:   new IntervalTrigger(3600),
            command:   new CommandSpec('App\Command\TestCommand'),
            misfire:   MisfirePolicy::skipMissed(),
            overlap:   OverlapPolicy::AllowConcurrent,
            timezone:  new DateTimeZone('UTC'),
            jitter:    null,
            status:    $status,
            tenantId:  $tenantId,
            sensitive: $sensitive,
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // loadSchedule
    // ══════════════════════════════════════════════════════════════════════════

    public function test_load_dynamic_schedule_by_id(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);

        $found = $this->makeService()->loadSchedule($schedule->id, 'tenant-1');

        self::assertSame($schedule->id->toString(), $found->id->toString());
    }

    public function test_load_static_schedule_by_id(): void
    {
        $registry = new StaticScheduleRegistry([FixedStaticScheduleDefinition::class]);
        $sut      = $this->makeService($registry);
        $id       = ScheduleId::fromString(FixedStaticScheduleDefinition::SCHEDULE_ID);

        $found = $sut->loadSchedule($id, null);

        self::assertSame(ScheduleSource::Static, $found->source);
        self::assertSame(FixedStaticScheduleDefinition::SCHEDULE_NAME, $found->name);
    }

    public function test_load_static_with_paused_override_returns_paused_schedule(): void
    {
        $registry = new StaticScheduleRegistry([FixedStaticScheduleDefinition::class]);
        $sut      = $this->makeService($registry);
        $id       = ScheduleId::fromString(FixedStaticScheduleDefinition::SCHEDULE_ID);

        $this->overrideStore->save(new ScheduleStatusOverride($id, ScheduleStatus::Paused, 'actor', null, $this->clock->now()));

        $found = $sut->loadSchedule($id, null);

        self::assertSame(ScheduleStatus::Paused, $found->status);
    }

    public function test_load_throws_when_not_found(): void
    {
        $this->expectException(ScheduleNotFoundException::class);
        $this->makeService()->loadSchedule(ScheduleId::generate(), 'tenant-1');
    }

    public function test_load_respects_tenant_isolation(): void
    {
        $schedule = $this->makeDynamic(tenantId: 'tenant-a');
        $this->dynamicStore->seed($schedule);

        $this->expectException(ScheduleNotFoundException::class);
        $this->makeService()->loadSchedule($schedule->id, 'tenant-b');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // pause — dynamic
    // ══════════════════════════════════════════════════════════════════════════

    public function test_pause_dynamic_schedule_saves_to_store(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);

        $result = $this->makeService()->pause($schedule->id, 'tenant-1', $this->actor, 'maintenance');

        self::assertSame(ScheduleStatus::Paused, $result->status);

        $stored = $this->dynamicStore->find($schedule->id, 'tenant-1');
        self::assertNotNull($stored);
        self::assertSame(ScheduleStatus::Paused, $stored->status);
    }

    public function test_pause_does_not_create_override_for_dynamic(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);

        $this->makeService()->pause($schedule->id, 'tenant-1', $this->actor);

        self::assertNull($this->overrideStore->find($schedule->id));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // pause — static
    // ══════════════════════════════════════════════════════════════════════════

    public function test_pause_static_schedule_creates_override_row(): void
    {
        $registry = new StaticScheduleRegistry([FixedStaticScheduleDefinition::class]);
        $id       = ScheduleId::fromString(FixedStaticScheduleDefinition::SCHEDULE_ID);

        $this->makeService($registry)->pause($id, null, $this->actor, 'for testing');

        $override = $this->overrideStore->find($id);
        self::assertNotNull($override);
        self::assertSame(ScheduleStatus::Paused, $override->status);
        self::assertSame('operator-1', $override->actorId);
        self::assertSame('for testing', $override->reason);
    }

    public function test_pause_static_returns_paused_schedule(): void
    {
        $registry = new StaticScheduleRegistry([FixedStaticScheduleDefinition::class]);
        $id       = ScheduleId::fromString(FixedStaticScheduleDefinition::SCHEDULE_ID);

        $result = $this->makeService($registry)->pause($id, null, $this->actor);

        self::assertSame(ScheduleStatus::Paused, $result->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // pause — RBAC
    // ══════════════════════════════════════════════════════════════════════════

    public function test_pause_throws_on_rbac_denial(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);
        $this->policy->deny();

        $this->expectException(ScheduleAccessDeniedException::class);
        $this->makeService()->pause($schedule->id, 'tenant-1', $this->actor);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // resume — dynamic
    // ══════════════════════════════════════════════════════════════════════════

    public function test_resume_dynamic_schedule_saves_active_to_store(): void
    {
        $schedule = $this->makeDynamic(status: ScheduleStatus::Paused);
        $this->dynamicStore->seed($schedule);

        $result = $this->makeService()->resume($schedule->id, 'tenant-1', $this->actor);

        self::assertSame(ScheduleStatus::Active, $result->status);

        $stored = $this->dynamicStore->find($schedule->id, 'tenant-1');
        self::assertSame(ScheduleStatus::Active, $stored?->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // resume — static
    // ══════════════════════════════════════════════════════════════════════════

    public function test_resume_static_removes_override_row(): void
    {
        $registry = new StaticScheduleRegistry([FixedStaticScheduleDefinition::class]);
        $id       = ScheduleId::fromString(FixedStaticScheduleDefinition::SCHEDULE_ID);
        $this->overrideStore->save(new ScheduleStatusOverride($id, ScheduleStatus::Paused, 'actor', null, $this->clock->now()));

        $this->makeService($registry)->resume($id, null, $this->actor);

        self::assertNull($this->overrideStore->find($id));
    }

    public function test_resume_static_returns_active_schedule(): void
    {
        $registry = new StaticScheduleRegistry([FixedStaticScheduleDefinition::class]);
        $id       = ScheduleId::fromString(FixedStaticScheduleDefinition::SCHEDULE_ID);
        $this->overrideStore->save(new ScheduleStatusOverride($id, ScheduleStatus::Paused, 'actor', null, $this->clock->now()));

        $result = $this->makeService($registry)->resume($id, null, $this->actor);

        self::assertSame(ScheduleStatus::Active, $result->status);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // runNow — happy path
    // ══════════════════════════════════════════════════════════════════════════

    public function test_run_now_dispatches_active_dynamic_schedule(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);

        $result = $this->makeService()->runNow($schedule->id, 'tenant-1', $this->actor);

        self::assertSame(FireDispatchResult::Dispatched, $result);
        self::assertTrue($this->dispatcher->wasDispatched());
    }

    public function test_run_now_slot_key_starts_with_manual_prefix(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);
        $this->makeService()->runNow($schedule->id, 'tenant-1', $this->actor);

        $fire = $this->dispatcher->lastFire();
        self::assertNotNull($fire);
        self::assertStringStartsWith('manual:', $fire->slot);
    }

    public function test_run_now_slot_key_is_unique_across_two_calls(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);
        $sut = $this->makeService();

        $sut->runNow($schedule->id, 'tenant-1', $this->actor);
        $sut->runNow($schedule->id, 'tenant-1', $this->actor);

        self::assertSame(2, $this->dispatcher->callCount());
        $slots = array_map(fn($c) => $c['fire']->slot, $this->dispatcher->calls);
        self::assertNotSame($slots[0], $slots[1]);
    }

    public function test_run_now_propagates_dispatcher_result(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);
        $this->dispatcher->setResult(FireDispatchResult::SkippedOverlap);

        $result = $this->makeService()->runNow($schedule->id, 'tenant-1', $this->actor);

        self::assertSame(FireDispatchResult::SkippedOverlap, $result);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // runNow — guards
    // ══════════════════════════════════════════════════════════════════════════

    public function test_run_now_throws_for_disabled_schedule(): void
    {
        $schedule = $this->makeDynamic(status: ScheduleStatus::Disabled);
        $this->dynamicStore->seed($schedule);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/disabled/i');
        $this->makeService()->runNow($schedule->id, 'tenant-1', $this->actor);
    }

    public function test_run_now_throws_logic_exception_for_sensitive_without_gate(): void
    {
        $schedule = $this->makeDynamic(sensitive: true);
        $this->dynamicStore->seed($schedule);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/FourEyesGate/');
        $this->makeService()->runNow($schedule->id, 'tenant-1', $this->actor);
    }

    public function test_run_now_throws_on_rbac_denial(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);
        $this->policy->deny();

        $this->expectException(ScheduleAccessDeniedException::class);
        $this->makeService()->runNow($schedule->id, 'tenant-1', $this->actor);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // requestApproval
    // ══════════════════════════════════════════════════════════════════════════

    public function test_request_approval_throws_logic_exception_without_gate(): void
    {
        $schedule = $this->makeDynamic(sensitive: true);
        $this->dynamicStore->seed($schedule);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/FourEyesGate/');
        $this->makeService()->requestApproval(
            $schedule->id,
            'tenant-1',
            \Vortos\Scheduler\Security\Approval\ApprovalAction::RunNow,
            $this->actor,
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // audit integration
    // ══════════════════════════════════════════════════════════════════════════

    public function test_pause_without_audit_does_not_throw(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);

        // No audit projector wired — must not throw
        $this->makeService()->pause($schedule->id, 'tenant-1', $this->actor);
        $this->addToAssertionCount(1);
    }

    public function test_resume_without_audit_does_not_throw(): void
    {
        $schedule = $this->makeDynamic(status: ScheduleStatus::Paused);
        $this->dynamicStore->seed($schedule);

        $this->makeService()->resume($schedule->id, 'tenant-1', $this->actor);
        $this->addToAssertionCount(1);
    }

    public function test_run_now_without_audit_does_not_throw(): void
    {
        $schedule = $this->makeDynamic();
        $this->dynamicStore->seed($schedule);

        $this->makeService()->runNow($schedule->id, 'tenant-1', $this->actor);
        $this->addToAssertionCount(1);
    }
}
