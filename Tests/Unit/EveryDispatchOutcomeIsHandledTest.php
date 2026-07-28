<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Console\ScheduleRunNowCommand;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Fire\CommandSpec;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Policy\MisfirePolicy;
use Vortos\Scheduler\Schedule\Policy\OverlapPolicy;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Schedule\Trigger\IntervalTrigger;
use Vortos\Scheduler\Service\ScheduleService;
use Vortos\Scheduler\Testing\FakeFireDispatcherPort;
use Vortos\Scheduler\Testing\FakeSchedulePolicy;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;
use Vortos\Scheduler\Testing\RecordingSchedulerMetrics;

/**
 * Adding a case to FireDispatchResult must not leave a consumer silently behind.
 *
 * CircuitOpen was added when the dispatch circuit breaker landed, and neither consumer was updated.
 * The two failures looked nothing alike, which is the point of testing them from one place:
 *
 *   - SchedulerMetrics wraps every emission in `catch (\Throwable)` so a broken metrics sink can
 *     never take down dispatch. UnhandledMatchError is a \Throwable, so the catch swallowed it and
 *     the fire counter simply stopped incrementing for exactly the outcome an operator most needs
 *     alerting on. Nothing anywhere reported a problem.
 *   - scheduler:run-now built its message from a match with no such arm, and its try/catch only
 *     names domain exceptions, so an operator firing a schedule while the breaker was open got an
 *     unhandled-match fatal instead of an explanation.
 *
 * Both are now driven off ::cases(), so a future case fails these tests rather than production.
 */
final class EveryDispatchOutcomeIsHandledTest extends TestCase
{
    /** @return iterable<string, array{FireDispatchResult}> */
    public static function outcomes(): iterable
    {
        foreach (FireDispatchResult::cases() as $case) {
            yield $case->name => [$case];
        }
    }

    #[DataProvider('outcomes')]
    public function test_the_fire_counter_is_emitted_for_every_outcome(FireDispatchResult $result): void
    {
        $recorder = new RecordingSchedulerMetrics();

        $recorder->schedulerMetrics->recordFireResult($result, 'schedule-1', null);

        $labels = $recorder->counterLabelsFor('vortos_scheduler_fires_total');

        self::assertNotEmpty(
            $labels,
            'no counter was recorded: the label match threw and the catch(\Throwable) swallowed it',
        );
        self::assertNotSame('', $labels[0]['result'] ?? '', 'the outcome must carry a non-empty result label');
    }

    /** Distinct labels — collapsing two outcomes into one label makes the metric unreadable. */
    public function test_every_outcome_gets_its_own_label(): void
    {
        $seen = [];
        foreach (FireDispatchResult::cases() as $case) {
            $recorder = new RecordingSchedulerMetrics();
            $recorder->schedulerMetrics->recordFireResult($case, 'schedule-1', null);
            $seen[] = $recorder->counterLabelsFor('vortos_scheduler_fires_total')[0]['result'] ?? '';
        }

        self::assertSame($seen, array_unique($seen));
    }

    #[DataProvider('outcomes')]
    public function test_run_now_reports_every_outcome_without_fatalling(FireDispatchResult $result): void
    {
        $dispatcher = new FakeFireDispatcherPort();
        $dispatcher->setResult($result);

        $store    = new InMemoryScheduleStore();
        $schedule = new Schedule(
            id:       ScheduleId::generate(),
            name:     'etl-job',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('App\Command\EtlCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
        $store->seed($schedule);

        $service = new ScheduleService(
            staticRegistry: new StaticScheduleRegistry([]),
            dynamicStore:   $store,
            overrideStore:  new InMemoryScheduleStatusOverrideStore(),
            policy:         new FakeSchedulePolicy(),
            clock:          new MutableClock(new DateTimeImmutable('2026-07-01T12:00:00+00:00')),
            fireDispatcher: $dispatcher,
        );

        $tester = new CommandTester(new ScheduleRunNowCommand($service));
        $tester->execute(['id' => $schedule->id->toString(), '--actor' => 'operator-1']);

        self::assertNotSame('', trim($tester->getDisplay()), 'the operator was told nothing about the outcome');
    }

    /**
     * An open circuit means the schedule did not run. Exiting 0 would tell a runbook step or a
     * wrapper script that the manual fire succeeded, so this outcome alone is reported as a failure.
     */
    public function test_an_open_circuit_is_reported_as_a_failure(): void
    {
        $dispatcher = new FakeFireDispatcherPort();
        $dispatcher->setResult(FireDispatchResult::CircuitOpen);

        $store    = new InMemoryScheduleStore();
        $schedule = new Schedule(
            id:       ScheduleId::generate(),
            name:     'etl-job',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('App\Command\EtlCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
        $store->seed($schedule);

        $service = new ScheduleService(
            staticRegistry: new StaticScheduleRegistry([]),
            dynamicStore:   $store,
            overrideStore:  new InMemoryScheduleStatusOverrideStore(),
            policy:         new FakeSchedulePolicy(),
            clock:          new MutableClock(new DateTimeImmutable('2026-07-01T12:00:00+00:00')),
            fireDispatcher: $dispatcher,
        );

        $tester = new CommandTester(new ScheduleRunNowCommand($service));
        $tester->execute(['id' => $schedule->id->toString(), '--actor' => 'operator-1']);

        self::assertSame(1, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('circuit breaker', $tester->getDisplay());
    }
}
