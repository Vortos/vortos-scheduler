<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Registry;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
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
use Vortos\Scheduler\Store\ScheduleStatusOverride;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;
use Vortos\Scheduler\Tests\Unit\Service\Support\FixedStaticScheduleDefinition;

/**
 * Tests for ScheduleResolver's override-store integration (S9).
 *
 * @covers \Vortos\Scheduler\Registry\ScheduleResolver::activeView
 * @covers \Vortos\Scheduler\Registry\ScheduleResolver::fullView
 */
final class ScheduleResolverOverrideTest extends TestCase
{
    private InMemoryScheduleStore $dynamicStore;
    private InMemoryScheduleStatusOverrideStore $overrideStore;

    protected function setUp(): void
    {
        $this->dynamicStore  = new InMemoryScheduleStore();
        $this->overrideStore = new InMemoryScheduleStatusOverrideStore();
    }

    private function makeResolver(?StaticScheduleRegistry $registry = null): ScheduleResolver
    {
        return new ScheduleResolver(
            $registry ?? new StaticScheduleRegistry([FixedStaticScheduleDefinition::class]),
            $this->dynamicStore,
            $this->overrideStore,
        );
    }

    private function makeStaticId(): ScheduleId
    {
        return ScheduleId::fromString(FixedStaticScheduleDefinition::SCHEDULE_ID);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // activeView — paused static should be suppressed
    // ══════════════════════════════════════════════════════════════════════════

    public function test_active_view_includes_static_when_no_override(): void
    {
        $resolver  = $this->makeResolver();
        $schedules = iterator_to_array($resolver->activeView());

        $ids = array_map(fn($s) => $s->id->toString(), $schedules);
        self::assertContains(FixedStaticScheduleDefinition::SCHEDULE_ID, $ids);
    }

    public function test_active_view_excludes_paused_static_schedule(): void
    {
        $id = $this->makeStaticId();
        $this->overrideStore->save(new ScheduleStatusOverride(
            $id, ScheduleStatus::Paused, 'operator', null, new DateTimeImmutable(),
        ));

        $resolver  = $this->makeResolver();
        $schedules = iterator_to_array($resolver->activeView());
        $ids       = array_map(fn($s) => $s->id->toString(), $schedules);

        self::assertNotContains(FixedStaticScheduleDefinition::SCHEDULE_ID, $ids);
    }

    public function test_active_view_includes_static_after_override_removed(): void
    {
        $id = $this->makeStaticId();
        $this->overrideStore->save(new ScheduleStatusOverride(
            $id, ScheduleStatus::Paused, 'operator', null, new DateTimeImmutable(),
        ));
        $this->overrideStore->remove($id);

        $resolver  = $this->makeResolver();
        $schedules = iterator_to_array($resolver->activeView());
        $ids       = array_map(fn($s) => $s->id->toString(), $schedules);

        self::assertContains(FixedStaticScheduleDefinition::SCHEDULE_ID, $ids);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // activeView — with no override store (backwards compatibility)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_active_view_without_override_store_yields_all_statics(): void
    {
        $registry = new StaticScheduleRegistry([FixedStaticScheduleDefinition::class]);
        $resolver = new ScheduleResolver($registry, $this->dynamicStore);

        $schedules = iterator_to_array($resolver->activeView());
        $ids       = array_map(fn($s) => $s->id->toString(), $schedules);

        self::assertContains(FixedStaticScheduleDefinition::SCHEDULE_ID, $ids);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // fullView — shows all schedules with status applied
    // ══════════════════════════════════════════════════════════════════════════

    public function test_full_view_shows_paused_static_with_paused_status(): void
    {
        $id = $this->makeStaticId();
        $this->overrideStore->save(new ScheduleStatusOverride(
            $id, ScheduleStatus::Paused, 'operator', null, new DateTimeImmutable(),
        ));

        $resolver  = $this->makeResolver();
        $schedules = iterator_to_array($resolver->fullView());

        $found = null;
        foreach ($schedules as $s) {
            if ($s->id->toString() === FixedStaticScheduleDefinition::SCHEDULE_ID) {
                $found = $s;
                break;
            }
        }

        self::assertNotNull($found);
        self::assertSame(ScheduleStatus::Paused, $found->status);
    }

    public function test_full_view_shows_active_static_when_no_override(): void
    {
        $resolver  = $this->makeResolver();
        $schedules = iterator_to_array($resolver->fullView());

        $found = null;
        foreach ($schedules as $s) {
            if ($s->id->toString() === FixedStaticScheduleDefinition::SCHEDULE_ID) {
                $found = $s;
                break;
            }
        }

        self::assertNotNull($found);
        self::assertSame(ScheduleStatus::Active, $found->status);
    }

    public function test_full_view_includes_dynamic_schedules(): void
    {
        $dynamic = new Schedule(
            id:       ScheduleId::generate(),
            name:     'dynamic-job',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('App\Command\TestCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Active,
            tenantId: null,
        );
        $this->dynamicStore->seed($dynamic);

        $resolver  = $this->makeResolver();
        $schedules = iterator_to_array($resolver->fullView());
        $ids       = array_map(fn($s) => $s->id->toString(), $schedules);

        self::assertContains($dynamic->id->toString(), $ids);
    }

    public function test_full_view_includes_paused_dynamic_schedules(): void
    {
        $dynamic = new Schedule(
            id:       ScheduleId::generate(),
            name:     'paused-dynamic',
            source:   ScheduleSource::Dynamic,
            trigger:  new IntervalTrigger(3600),
            command:  new CommandSpec('App\Command\TestCommand'),
            misfire:  MisfirePolicy::skipMissed(),
            overlap:  OverlapPolicy::AllowConcurrent,
            timezone: new DateTimeZone('UTC'),
            jitter:   null,
            status:   ScheduleStatus::Paused,
            tenantId: null,
        );
        $this->dynamicStore->seed($dynamic);

        $resolver  = $this->makeResolver();
        $schedules = iterator_to_array($resolver->fullView());
        $names     = array_map(fn($s) => $s->name, $schedules);

        self::assertContains('paused-dynamic', $names);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // fullView — multiple overrides
    // ══════════════════════════════════════════════════════════════════════════

    public function test_full_view_no_override_store_still_works(): void
    {
        $registry = new StaticScheduleRegistry([FixedStaticScheduleDefinition::class]);
        $resolver = new ScheduleResolver($registry, $this->dynamicStore);
        $schedules = iterator_to_array($resolver->fullView());
        self::assertNotEmpty($schedules);
    }
}
