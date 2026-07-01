<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Console;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Console\RetentionOverrideSetCommand;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Service\ScheduleService;
use Vortos\Scheduler\Testing\FakeFireDispatcherPort;
use Vortos\Scheduler\Testing\FakeSchedulePolicy;
use Vortos\Scheduler\Testing\InMemoryRunRetentionOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStatusOverrideStore;
use Vortos\Scheduler\Testing\InMemoryScheduleStore;

/**
 * @covers \Vortos\Scheduler\Console\RetentionOverrideSetCommand
 */
final class RetentionOverrideSetCommandTest extends TestCase
{
    private FakeSchedulePolicy               $policy;
    private InMemoryRunRetentionOverrideStore $retentionStore;

    protected function setUp(): void
    {
        $this->policy         = new FakeSchedulePolicy();
        $this->retentionStore = new InMemoryRunRetentionOverrideStore();
    }

    private function makeService(): ScheduleService
    {
        return new ScheduleService(
            staticRegistry:         new StaticScheduleRegistry([]),
            dynamicStore:            new InMemoryScheduleStore(),
            overrideStore:           new InMemoryScheduleStatusOverrideStore(),
            policy:                  $this->policy,
            clock:                   new MutableClock(new DateTimeImmutable('2026-07-01T12:00:00+00:00')),
            fireDispatcher:          new FakeFireDispatcherPort(),
            retentionOverrideStore: $this->retentionStore,
        );
    }

    public function test_set_succeeds_and_exits_zero(): void
    {
        $tester = new CommandTester(new RetentionOverrideSetCommand($this->makeService()));

        $tester->execute(['--tenant' => 'tenant-1', '--days' => '90', '--actor' => 'admin-1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('90 day(s)', $tester->getDisplay());
        self::assertSame(90, $this->retentionStore->find('tenant-1')?->retentionDays);
    }

    public function test_set_zero_days_reports_legal_hold(): void
    {
        $tester = new CommandTester(new RetentionOverrideSetCommand($this->makeService()));

        $tester->execute(['--tenant' => 'tenant-hold', '--days' => '0', '--actor' => 'admin-1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('legal hold', $tester->getDisplay());
    }

    public function test_set_negative_days_exits_failure(): void
    {
        $tester = new CommandTester(new RetentionOverrideSetCommand($this->makeService()));

        $tester->execute(['--tenant' => 'tenant-1', '--days' => '-1', '--actor' => 'admin-1']);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function test_set_non_numeric_days_exits_failure(): void
    {
        $tester = new CommandTester(new RetentionOverrideSetCommand($this->makeService()));

        $tester->execute(['--tenant' => 'tenant-1', '--days' => 'abc', '--actor' => 'admin-1']);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function test_set_access_denied_exits_failure(): void
    {
        $this->policy->deny();
        $tester = new CommandTester(new RetentionOverrideSetCommand($this->makeService()));

        $tester->execute(['--tenant' => 'tenant-1', '--days' => '30', '--actor' => 'user-1']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Access denied', $tester->getDisplay());
    }
}
