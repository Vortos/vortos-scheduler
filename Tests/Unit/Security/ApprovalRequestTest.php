<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalRequest;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;

final class ApprovalRequestTest extends TestCase
{
    private function makeRequest(ApprovalStatus $status = ApprovalStatus::Pending): ApprovalRequest
    {
        return new ApprovalRequest(
            id:          'uuid-1',
            scheduleId:  ScheduleId::generate(),
            action:      ApprovalAction::Activate,
            status:      $status,
            requestedBy: 'user-1',
            requestedAt: new DateTimeImmutable('2026-01-01 10:00:00'),
            expiresAt:   new DateTimeImmutable('2026-01-02 10:00:00'),
            reason:      'quarterly run',
            resolvedBy:  null,
            resolvedAt:  null,
        );
    }

    public function test_is_pending_when_pending(): void
    {
        self::assertTrue($this->makeRequest(ApprovalStatus::Pending)->isPending());
    }

    public function test_is_not_pending_when_approved(): void
    {
        self::assertFalse($this->makeRequest(ApprovalStatus::Approved)->isPending());
    }

    public function test_is_approved_when_approved(): void
    {
        self::assertTrue($this->makeRequest(ApprovalStatus::Approved)->isApproved());
    }

    public function test_is_expired_when_pending_and_past_expiry(): void
    {
        $request = $this->makeRequest(ApprovalStatus::Pending);
        $now     = new DateTimeImmutable('2026-01-02 11:00:00'); // after expiresAt

        self::assertTrue($request->isExpiredAt($now));
    }

    public function test_is_not_expired_when_before_expiry(): void
    {
        $request = $this->makeRequest(ApprovalStatus::Pending);
        $now     = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertFalse($request->isExpiredAt($now));
    }

    public function test_is_not_expired_when_already_approved(): void
    {
        $request = $this->makeRequest(ApprovalStatus::Approved);
        $now     = new DateTimeImmutable('2026-01-03 00:00:00'); // long after expiry

        self::assertFalse($request->isExpiredAt($now));
    }

    public function test_with_resolution_preserves_all_fields_except_resolution(): void
    {
        $original  = $this->makeRequest();
        $resolvedAt = new DateTimeImmutable('2026-01-01 12:00:00');
        $resolved  = $original->withResolution(ApprovalStatus::Approved, 'approver-1', $resolvedAt);

        self::assertSame($original->id, $resolved->id);
        self::assertSame($original->scheduleId, $resolved->scheduleId);
        self::assertSame(ApprovalStatus::Approved, $resolved->status);
        self::assertSame('approver-1', $resolved->resolvedBy);
        self::assertSame($resolvedAt, $resolved->resolvedAt);
        self::assertSame($original->requestedBy, $resolved->requestedBy);
    }

    public function test_approval_status_terminal_states(): void
    {
        self::assertFalse(ApprovalStatus::Pending->isTerminal());
        self::assertTrue(ApprovalStatus::Approved->isTerminal());
        self::assertTrue(ApprovalStatus::Rejected->isTerminal());
        self::assertTrue(ApprovalStatus::Expired->isTerminal());
    }
}
