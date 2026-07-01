<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Scheduler\Security\NullSchedulePolicy;
use Vortos\Scheduler\Tests\Unit\Security\Support\ScheduleFactory;

final class NullSchedulePolicyTest extends TestCase
{
    private NullSchedulePolicy $policy;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->policy = new NullSchedulePolicy($this->logger);
    }

    public function test_allows_create_and_logs_warning(): void
    {
        $identity = new UserIdentity('user-1', []);
        $schedule = ScheduleFactory::normal();

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('RBAC is disabled'), self::arrayHasKey('action'));

        $this->policy->assertCanCreate($identity, $schedule);
        $this->addToAssertionCount(1);
    }

    public function test_allows_update_and_logs_warning(): void
    {
        $identity = new UserIdentity('user-1', []);
        $this->logger->expects(self::once())->method('warning');

        $this->policy->assertCanUpdate($identity, ScheduleFactory::normal());
        $this->addToAssertionCount(1);
    }

    public function test_allows_pause_and_logs_warning(): void
    {
        $identity = new UserIdentity('user-1', []);
        $this->logger->expects(self::once())->method('warning');

        $this->policy->assertCanPause($identity, ScheduleFactory::normal());
        $this->addToAssertionCount(1);
    }

    public function test_allows_delete_and_logs_warning(): void
    {
        $identity = new UserIdentity('user-1', []);
        $this->logger->expects(self::once())->method('warning');

        $this->policy->assertCanDelete($identity, ScheduleFactory::normal());
        $this->addToAssertionCount(1);
    }

    public function test_allows_run_now_and_logs_warning(): void
    {
        $identity = new UserIdentity('user-1', []);
        $this->logger->expects(self::once())->method('warning');

        $this->policy->assertCanRunNow($identity, ScheduleFactory::normal());
        $this->addToAssertionCount(1);
    }

    public function test_allows_manage_retention_and_logs_warning(): void
    {
        $identity = new UserIdentity('user-1', []);
        $this->logger->expects(self::once())->method('warning');

        $this->policy->assertCanManageRetention($identity, 'tenant-1');
        self::assertTrue($this->policy->canManageRetention($identity, 'tenant-1'));
    }

    public function test_log_message_includes_action_and_identity(): void
    {
        $identity = new UserIdentity('the-user-id', []);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                self::anything(),
                self::callback(fn (array $ctx) =>
                    ($ctx['action'] ?? null) === 'create'
                    && ($ctx['identity_id'] ?? null) === 'the-user-id'
                ),
            );

        $this->policy->assertCanCreate($identity, ScheduleFactory::normal());
    }
}
