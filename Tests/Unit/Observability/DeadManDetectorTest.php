<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Observability;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Observability\DeadManDetector;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\Trigger;
use Vortos\Scheduler\Store\PruneResult;
use Vortos\Scheduler\Store\ScheduleRunStoreInterface;
use Vortos\Scheduler\Tests\Unit\Security\Support\StubAllowlistedCommand;

final class DeadManDetectorTest extends TestCase
{
    private const ENV = 'testing';
    private const TOLERANCE = 600;

    private DateTimeImmutable $now;
    private MutableClock $clock;

    protected function setUp(): void
    {
        $this->now   = new DateTimeImmutable('2026-07-01T10:00:00+00:00');
        $this->clock = new MutableClock($this->now);
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function test_empty_schedules_does_nothing(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $detector   = $this->makeDetector($dispatcher, []);

        $detector->check([]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    public function test_paused_schedule_is_skipped(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $detector   = $this->makeDetector($dispatcher, []);
        $schedule   = $this->makeSchedule(status: ScheduleStatus::Paused);

        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    public function test_opted_out_schedule_is_skipped(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $detector   = $this->makeDetector($dispatcher, []);
        $schedule   = $this->makeSchedule(metadata: ['deadman_enabled' => 'false']);

        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    public function test_schedule_not_yet_due_in_window_does_not_alert(): void
    {
        // nextRunAfter(windowStart) returns a time AFTER now → schedule not yet due
        $windowStart = $this->now->modify(sprintf('-%d seconds', self::TOLERANCE));
        $trigger = $this->makeTrigger(function (DateTimeImmutable $after) use ($windowStart) {
            // Auto-bump calls: return null so auto-bump is bypassed
            if ($after->getTimestamp() >= $this->now->getTimestamp()) {
                return null;
            }
            // windowStart call — return now + 100s (in the future relative to now)
            return $this->now->modify('+100 seconds');
        });

        $dispatcher = new SpyAlertDispatcher();
        $detector   = $this->makeDetector($dispatcher, []);
        $schedule   = $this->makeSchedule(trigger: $trigger);

        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    // ── Alert-raising scenarios ───────────────────────────────────────────────

    public function test_schedule_never_fired_raises_critical_alert(): void
    {
        $trigger    = $this->makePastDueTrigger();
        $dispatcher = new SpyAlertDispatcher();
        // No runs in store: all schedule IDs return null
        $detector = $this->makeDetector($dispatcher, []);
        $schedule = $this->makeSchedule(trigger: $trigger, metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);

        $detector->check([$schedule]);

        self::assertCount(1, $dispatcher->dispatched());
        self::assertSame(Severity::Critical, $dispatcher->dispatched()[0]->severity);
    }

    public function test_alert_source_is_scheduler(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertSame(AlertSource::Scheduler, $dispatcher->dispatched()[0]->source);
    }

    public function test_alert_title_contains_schedule_name(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(name: 'nightly-report', trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertStringContainsString('nightly-report', $dispatcher->dispatched()[0]->title);
    }

    public function test_alert_carries_schedule_id_label(): void
    {
        $scheduleId = ScheduleId::generate();
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(id: $scheduleId, trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertSame($scheduleId->toString(), $dispatcher->dispatched()[0]->labels['schedule_id']);
    }

    public function test_alert_carries_tenant_id(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(
            trigger:  $this->makePastDueTrigger(),
            tenantId: 'tenant-99',
            metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE],
        );

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertSame('tenant-99', $dispatcher->dispatched()[0]->tenantId);
    }

    // ── No-alert when dispatched in window ───────────────────────────────────

    public function test_schedule_dispatched_within_window_no_alert(): void
    {
        $windowStart = $this->now->modify(sprintf('-%d seconds', self::TOLERANCE));
        $lastDispatch = $windowStart->modify('+60 seconds'); // inside window

        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $detector   = $this->makeDetector($dispatcher, [$schedule->id->toString() => $lastDispatch]);

        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    public function test_schedule_dispatched_exactly_at_window_start_no_alert(): void
    {
        $windowStart  = $this->now->modify(sprintf('-%d seconds', self::TOLERANCE));
        $lastDispatch = $windowStart; // exactly at window start

        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $detector   = $this->makeDetector($dispatcher, [$schedule->id->toString() => $lastDispatch]);

        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    // ── Alert when last dispatch is outside window ────────────────────────────

    public function test_schedule_dispatched_before_window_raises_alert(): void
    {
        $windowStart  = $this->now->modify(sprintf('-%d seconds', self::TOLERANCE));
        $lastDispatch = $windowStart->modify('-1 second'); // 1 second before window

        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $detector   = $this->makeDetector($dispatcher, [$schedule->id->toString() => $lastDispatch]);

        $detector->check([$schedule]);

        self::assertCount(1, $dispatcher->dispatched());
    }

    // ── Custom routing override ───────────────────────────────────────────────

    public function test_alert_routing_override_applied(): void
    {
        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(
            trigger:  $this->makePastDueTrigger(),
            metadata: [
                'deadman_tolerance_sec'   => (string) self::TOLERANCE,
                'deadman_alert_routing'   => 'pagerduty,slack',
            ],
        );

        $this->makeDetector($dispatcher, [])->check([$schedule]);

        self::assertNotNull($dispatcher->lastRoutingOverride);
        self::assertContains('pagerduty', $dispatcher->lastRoutingOverride);
        self::assertContains('slack', $dispatcher->lastRoutingOverride);
    }

    // ── Explicit tolerance from metadata ─────────────────────────────────────

    public function test_explicit_tolerance_via_metadata(): void
    {
        // Use a 3600s tolerance — schedule dispatched 1801s ago (within 3600s window)
        $lastDispatch = $this->now->modify('-1801 seconds');
        $dispatcher   = new SpyAlertDispatcher();
        $schedule     = $this->makeSchedule(
            trigger:  $this->makePastDueTrigger(),
            metadata: ['deadman_tolerance_sec' => '3600'],
        );
        $detector = $this->makeDetector($dispatcher, [$schedule->id->toString() => $lastDispatch]);

        $detector->check([$schedule]);

        // 1801s ago is within the 3600s window → no alert
        self::assertCount(0, $dispatcher->dispatched());
    }

    // ── Safety: never throws ─────────────────────────────────────────────────

    public function test_run_store_query_failure_is_swallowed(): void
    {
        $runStore = new class implements ScheduleRunStoreInterface {
            public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array
            {
                throw new \RuntimeException('DB gone');
            }

            // Required stubs
            public function insertRun(\Vortos\Scheduler\Fire\ScheduleRun $run): void {}
            public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
            public function findRunState(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\RunState { return null; }
            public function findRunBySlot(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\ScheduleRun { return null; }
            public function transitionRunState(string $runId, \Vortos\Scheduler\Fire\RunState $newState, \DateTimeImmutable $at): void {}
            public function pruneOldRuns(\DateTimeImmutable $olderThan, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult { return new PruneResult(0, false); }
        };

        $dispatcher = new SpyAlertDispatcher();
        $schedule   = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $detector   = new DeadManDetector($runStore, $dispatcher, $this->clock, self::ENV, self::TOLERANCE);

        // Must NOT throw
        $detector->check([$schedule]);

        self::assertCount(0, $dispatcher->dispatched());
    }

    public function test_broken_alert_dispatcher_is_swallowed(): void
    {
        $broken = new class implements AlertDispatcherInterface {
            public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
            {
                throw new \RuntimeException('alert backend down');
            }
        };

        $schedule = $this->makeSchedule(trigger: $this->makePastDueTrigger(), metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $detector = $this->makeDetector($broken, []);

        // Must NOT throw
        $detector->check([$schedule]);

        $this->addToAssertionCount(1);
    }

    public function test_multiple_schedules_one_failing_continues_others(): void
    {
        $badTrigger = $this->makeTrigger(function (\DateTimeImmutable $after) {
            if ($after->getTimestamp() < $this->now->getTimestamp()) {
                // windowStart call
                throw new \RuntimeException('trigger explodes');
            }

            return null;
        });

        $goodTrigger = $this->makePastDueTrigger();

        $dispatcher = new SpyAlertDispatcher();
        $badSchedule  = $this->makeSchedule(trigger: $badTrigger, metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);
        $goodSchedule = $this->makeSchedule(trigger: $goodTrigger, metadata: ['deadman_tolerance_sec' => (string) self::TOLERANCE]);

        $this->makeDetector($dispatcher, [])->check([$badSchedule, $goodSchedule]);

        // Good schedule still raised an alert despite bad one exploding
        self::assertCount(1, $dispatcher->dispatched());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makePastDueTrigger(): Trigger
    {
        return $this->makeTrigger(function (DateTimeImmutable $after) {
            // If called with now or later → auto-bump bypass (return null)
            if ($after->getTimestamp() >= $this->now->getTimestamp()) {
                return null;
            }
            // Window-start call → return a time 300s after after (well before now)
            return $after->modify('+300 seconds');
        });
    }

    private function makeTrigger(\Closure $resolver): Trigger
    {
        return new class($resolver) implements Trigger {
            public function __construct(private \Closure $resolver) {}

            public function nextRunAfter(DateTimeImmutable $after): ?DateTimeImmutable
            {
                return ($this->resolver)($after);
            }

            public function describe(): string { return 'stub'; }
        };
    }

    private function makeDetector(
        AlertDispatcherInterface $dispatcher,
        array $lastDispatchMap,
    ): DeadManDetector {
        $runStore = $this->makeRunStore($lastDispatchMap);

        return new DeadManDetector(
            $runStore,
            $dispatcher,
            $this->clock,
            self::ENV,
            self::TOLERANCE,
            new NullLogger(),
        );
    }

    private function makeRunStore(array $lastDispatchMap): ScheduleRunStoreInterface
    {
        return new class($lastDispatchMap) implements ScheduleRunStoreInterface {
            public function __construct(private array $lastDispatchMap) {}

            public function findLastDispatchTimes(array $scheduleIds, ?string $tenantId): array
            {
                $result = [];
                foreach ($scheduleIds as $id) {
                    $key          = is_string($id) ? $id : $id->toString();
                    $result[$key] = $this->lastDispatchMap[$key] ?? null;
                }

                return $result;
            }

            public function insertRun(\Vortos\Scheduler\Fire\ScheduleRun $run): void {}
            public function findLastSlots(array $scheduleIds, ?string $tenantId): array { return []; }
            public function findRunState(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\RunState { return null; }
            public function findRunBySlot(\Vortos\Scheduler\Schedule\ScheduleId $scheduleId, string $slot, ?string $tenantId): ?\Vortos\Scheduler\Fire\ScheduleRun { return null; }
            public function transitionRunState(string $runId, \Vortos\Scheduler\Fire\RunState $newState, \DateTimeImmutable $at): void {}
            public function pruneOldRuns(\DateTimeImmutable $olderThan, ?string $tenantId = null, array $excludeTenantIds = []): PruneResult { return new PruneResult(0, false); }
        };
    }

    private function makeSchedule(
        ?ScheduleId $id = null,
        string $name = 'test-schedule',
        ?Trigger $trigger = null,
        ScheduleStatus $status = ScheduleStatus::Active,
        ?string $tenantId = 'tenant-1',
        array $metadata = [],
    ): Schedule {
        return new Schedule(
            id:       $id ?? ScheduleId::generate(),
            name:     $name,
            source:   ScheduleSource::Dynamic,
            trigger:  $trigger ?? $this->makePastDueTrigger(),
            command:  new CommandSpec(StubAllowlistedCommand::class, []),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::Skip,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   $status,
            tenantId: $tenantId,
            metadata: $metadata,
        );
    }
}

// ── Test doubles ──────────────────────────────────────────────────────────────

final class SpyAlertDispatcher implements AlertDispatcherInterface
{
    /** @var list<AlertEvent> */
    private array $dispatched = [];

    public ?array $lastRoutingOverride = null;

    public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
    {
        $this->dispatched[]        = $event;
        $this->lastRoutingOverride = $routingOverride;

        return new DispatchResult(DedupeDecision::New, []);
    }

    /** @return list<AlertEvent> */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}
