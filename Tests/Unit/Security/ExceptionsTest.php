<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;
use Vortos\Scheduler\Security\Exception\CommandNotAllowlistedException;
use Vortos\Scheduler\Security\Exception\FourEyesApprovalRequiredException;
use Vortos\Scheduler\Security\Exception\InvalidCommandPayloadException;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Security\Exception\SelfApprovalException;

final class ExceptionsTest extends TestCase
{
    public function test_command_not_allowlisted_exception_carries_class(): void
    {
        $e = new CommandNotAllowlistedException('App\Command\Dangerous');

        self::assertSame('App\Command\Dangerous', $e->commandClass);
        self::assertStringContainsString('App\Command\Dangerous', $e->getMessage());
        self::assertStringContainsString('allowlisted', $e->getMessage());
    }

    public function test_invalid_command_payload_exception_message(): void
    {
        $e = new InvalidCommandPayloadException('App\Command\Cmd', 'missing required field');

        self::assertStringContainsString('App\Command\Cmd', $e->getMessage());
        self::assertStringContainsString('missing required field', $e->getMessage());
    }

    public function test_invalid_command_payload_wraps_previous(): void
    {
        $prev = new \RuntimeException('inner');
        $e    = new InvalidCommandPayloadException('Cmd', 'reason', $prev);

        self::assertSame($prev, $e->getPrevious());
    }

    public function test_schedule_access_denied_carries_action_and_identity(): void
    {
        $e = new ScheduleAccessDeniedException('create', 'user-abc');

        self::assertSame('create', $e->action);
        self::assertSame('user-abc', $e->identityId);
        self::assertStringContainsString('user-abc', $e->getMessage());
        self::assertStringContainsString('create', $e->getMessage());
    }

    public function test_four_eyes_not_requested_message(): void
    {
        $e = FourEyesApprovalRequiredException::notRequested('sched-1', ApprovalAction::Activate);

        self::assertStringContainsString('sched-1', $e->getMessage());
        self::assertStringContainsString('activate', $e->getMessage());
        self::assertStringContainsString('no approval has been requested', $e->getMessage());
    }

    public function test_four_eyes_not_approved_message(): void
    {
        $e = FourEyesApprovalRequiredException::notApproved('sched-1', ApprovalAction::RunNow, ApprovalStatus::Pending);

        self::assertStringContainsString('sched-1', $e->getMessage());
        self::assertStringContainsString('run-now', $e->getMessage());
        self::assertStringContainsString('pending', $e->getMessage());
    }

    public function test_four_eyes_expired_message(): void
    {
        $e = FourEyesApprovalRequiredException::expired('approval-uuid-1');

        self::assertStringContainsString('approval-uuid-1', $e->getMessage());
        self::assertStringContainsString('expired', $e->getMessage());
    }

    public function test_four_eyes_already_resolved_message(): void
    {
        $e = FourEyesApprovalRequiredException::alreadyResolved('approval-1', ApprovalStatus::Rejected);

        self::assertStringContainsString('approval-1', $e->getMessage());
        self::assertStringContainsString('rejected', $e->getMessage());
    }

    public function test_self_approval_exception_is_four_eyes_subtype(): void
    {
        $e = new SelfApprovalException('approval-1', 'user-1');

        self::assertInstanceOf(FourEyesApprovalRequiredException::class, $e);
        self::assertSame('approval-1', $e->approvalId);
        self::assertSame('user-1', $e->actorId);
        self::assertStringContainsString('user-1', $e->getMessage());
        self::assertStringContainsString('approval-1', $e->getMessage());
        self::assertStringContainsString('4-eyes', $e->getMessage());
    }
}
