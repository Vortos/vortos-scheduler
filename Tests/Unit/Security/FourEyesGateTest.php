<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalRequest;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;
use Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface;
use Vortos\Scheduler\Security\Exception\FourEyesApprovalRequiredException;
use Vortos\Scheduler\Security\Exception\SelfApprovalException;
use Vortos\Scheduler\Security\FourEyesGate;
use Vortos\Scheduler\Tests\Unit\Security\Support\FakeClock;
use Vortos\Scheduler\Tests\Unit\Security\Support\ScheduleFactory;

final class FourEyesGateTest extends TestCase
{
    private FourEyesApprovalStoreInterface&MockObject $store;
    private FakeClock $clock;
    private FourEyesGate $gate;

    protected function setUp(): void
    {
        $this->store = $this->createMock(FourEyesApprovalStoreInterface::class);
        $this->clock = new FakeClock(new DateTimeImmutable('2026-01-15 10:00:00'));
        $this->gate  = new FourEyesGate($this->store, $this->clock, 86400);
    }

    // ── requiresApproval ──────────────────────────────────────────────────────

    public function test_requires_approval_for_sensitive_schedule(): void
    {
        $schedule = ScheduleFactory::sensitive();
        self::assertTrue($this->gate->requiresApproval($schedule));
    }

    public function test_does_not_require_approval_for_non_sensitive_schedule(): void
    {
        $schedule = ScheduleFactory::normal();
        self::assertFalse($this->gate->requiresApproval($schedule));
    }

    // ── requestApproval ───────────────────────────────────────────────────────

    public function test_request_approval_creates_new_pending_request(): void
    {
        $schedule = ScheduleFactory::sensitive();

        $this->store->expects(self::once())
            ->method('findPending')
            ->with($schedule->id, ApprovalAction::Activate)
            ->willReturn(null);

        $this->store->expects(self::once())
            ->method('save')
            ->with(self::callback(fn (ApprovalRequest $r) =>
                $r->status === ApprovalStatus::Pending
                && $r->action === ApprovalAction::Activate
                && $r->requestedBy === 'user-1'
                && $r->reason === 'quarterly batch'
            ));

        $request = $this->gate->requestApproval($schedule, ApprovalAction::Activate, 'user-1', 'quarterly batch');

        self::assertSame(ApprovalStatus::Pending, $request->status);
        self::assertSame('user-1', $request->requestedBy);
    }

    public function test_request_approval_is_idempotent_when_pending_exists(): void
    {
        $schedule  = ScheduleFactory::sensitive();
        $existing  = $this->makePendingRequest($schedule->id);

        $this->store->expects(self::once())
            ->method('findPending')
            ->willReturn($existing);

        $this->store->expects(self::never())->method('save');

        $result = $this->gate->requestApproval($schedule, ApprovalAction::Activate, 'user-1');

        self::assertSame($existing, $result);
    }

    public function test_request_sets_expiry_based_on_ttl(): void
    {
        $schedule = ScheduleFactory::sensitive();
        $gate     = new FourEyesGate($this->store, $this->clock, 3600); // 1 hour TTL

        $this->store->method('findPending')->willReturn(null);
        $this->store->expects(self::once())->method('save')
            ->with(self::callback(fn (ApprovalRequest $r) =>
                $r->expiresAt == new DateTimeImmutable('2026-01-15 11:00:00') // +3600s
            ));

        $gate->requestApproval($schedule, ApprovalAction::Activate, 'user-1');
    }

    // ── approve ───────────────────────────────────────────────────────────────

    public function test_approve_transitions_to_approved(): void
    {
        $schedule = ScheduleFactory::sensitive();
        $request  = $this->makePendingRequest($schedule->id);

        $this->store->method('findById')->with($request->id)->willReturn($request);
        $this->store->expects(self::once())->method('save')
            ->with(self::callback(fn (ApprovalRequest $r) =>
                $r->status === ApprovalStatus::Approved && $r->resolvedBy === 'approver-1'
            ));

        $approved = $this->gate->approve($request->id, 'approver-1');

        self::assertSame(ApprovalStatus::Approved, $approved->status);
        self::assertSame('approver-1', $approved->resolvedBy);
    }

    public function test_approve_throws_on_self_approval(): void
    {
        $schedule = ScheduleFactory::sensitive();
        $request  = $this->makePendingRequest($schedule->id, requestedBy: 'user-1');

        $this->store->method('findById')->willReturn($request);
        $this->store->expects(self::never())->method('save');

        $this->expectException(SelfApprovalException::class);
        $this->gate->approve($request->id, 'user-1');
    }

    public function test_approve_throws_when_request_not_found(): void
    {
        $this->store->method('findById')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->gate->approve('non-existent-id', 'approver-1');
    }

    public function test_approve_throws_on_already_approved_request(): void
    {
        $schedule = ScheduleFactory::sensitive();
        $approved = $this->makePendingRequest($schedule->id)->withResolution(
            ApprovalStatus::Approved,
            'other-approver',
            new DateTimeImmutable('2026-01-15 10:00:00'),
        );

        $this->store->method('findById')->willReturn($approved);

        $this->expectException(FourEyesApprovalRequiredException::class);
        $this->gate->approve($approved->id, 'approver-2');
    }

    public function test_approve_throws_on_expired_request(): void
    {
        $schedule = ScheduleFactory::sensitive();
        $request  = $this->makePendingRequest($schedule->id, expiresAt: new DateTimeImmutable('2026-01-14 00:00:00'));

        $this->store->method('findById')->willReturn($request);

        $this->expectException(FourEyesApprovalRequiredException::class);
        $this->expectExceptionMessage('expired');
        $this->gate->approve($request->id, 'approver-1');
    }

    // ── reject ────────────────────────────────────────────────────────────────

    public function test_reject_transitions_to_rejected(): void
    {
        $schedule = ScheduleFactory::sensitive();
        $request  = $this->makePendingRequest($schedule->id);

        $this->store->method('findById')->with($request->id)->willReturn($request);
        $this->store->expects(self::once())->method('save')
            ->with(self::callback(fn (ApprovalRequest $r) =>
                $r->status === ApprovalStatus::Rejected && $r->resolvedBy === 'reviewer-1'
            ));

        $rejected = $this->gate->reject($request->id, 'reviewer-1');

        self::assertSame(ApprovalStatus::Rejected, $rejected->status);
    }

    // ── assertApproved ────────────────────────────────────────────────────────

    public function test_assert_approved_passes_for_non_sensitive_schedule(): void
    {
        $schedule = ScheduleFactory::normal();
        $this->store->expects(self::never())->method('findPending');

        $this->gate->assertApproved($schedule, ApprovalAction::Activate);
        $this->addToAssertionCount(1);
    }

    public function test_assert_approved_passes_when_approved_request_exists(): void
    {
        $schedule = ScheduleFactory::sensitive();
        $approved = $this->makePendingRequest($schedule->id)->withResolution(
            ApprovalStatus::Approved,
            'approver-1',
            new DateTimeImmutable('2026-01-15 11:00:00'),
        );

        $this->store->method('findPending')->willReturn($approved);

        $this->gate->assertApproved($schedule, ApprovalAction::Activate);
        $this->addToAssertionCount(1);
    }

    public function test_assert_approved_throws_when_no_request_exists(): void
    {
        $schedule = ScheduleFactory::sensitive();
        $this->store->method('findPending')->willReturn(null);

        $this->expectException(FourEyesApprovalRequiredException::class);
        $this->expectExceptionMessage('no approval has been requested');
        $this->gate->assertApproved($schedule, ApprovalAction::Activate);
    }

    public function test_assert_approved_throws_when_still_pending(): void
    {
        $schedule = ScheduleFactory::sensitive();
        $request  = $this->makePendingRequest($schedule->id);

        $this->store->method('findPending')->willReturn($request);

        $this->expectException(FourEyesApprovalRequiredException::class);
        $this->gate->assertApproved($schedule, ApprovalAction::Activate);
    }

    public function test_assert_approved_throws_when_request_expired(): void
    {
        $schedule = ScheduleFactory::sensitive();
        $expired  = $this->makePendingRequest($schedule->id, expiresAt: new DateTimeImmutable('2026-01-14 00:00:00'));

        $this->store->method('findPending')->willReturn($expired);

        $this->expectException(FourEyesApprovalRequiredException::class);
        $this->expectExceptionMessage('expired');
        $this->gate->assertApproved($schedule, ApprovalAction::Activate);
    }

    public function test_run_now_action_is_gated_independently_from_activate(): void
    {
        $schedule      = ScheduleFactory::sensitive();
        $activateReq   = $this->makePendingRequest($schedule->id, action: ApprovalAction::Activate);
        $runNowApproved = $this->makePendingRequest($schedule->id, action: ApprovalAction::RunNow)
            ->withResolution(ApprovalStatus::Approved, 'approver-1', new DateTimeImmutable('2026-01-15 10:30:00'));

        $this->store->method('findPending')
            ->willReturnCallback(fn (ScheduleId $id, ApprovalAction $action) =>
                $action === ApprovalAction::RunNow ? $runNowApproved : $activateReq
            );

        // RunNow is approved — should pass
        $this->gate->assertApproved($schedule, ApprovalAction::RunNow);
        $this->addToAssertionCount(1);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makePendingRequest(
        ScheduleId      $scheduleId,
        string          $requestedBy = 'user-1',
        ApprovalAction  $action = ApprovalAction::Activate,
        ?DateTimeImmutable $expiresAt = null,
    ): ApprovalRequest {
        return new ApprovalRequest(
            id:          'approval-uuid-1',
            scheduleId:  $scheduleId,
            action:      $action,
            status:      ApprovalStatus::Pending,
            requestedBy: $requestedBy,
            requestedAt: new DateTimeImmutable('2026-01-15 09:00:00'),
            expiresAt:   $expiresAt ?? new DateTimeImmutable('2026-01-16 09:00:00'),
            reason:      null,
            resolvedBy:  null,
            resolvedAt:  null,
        );
    }
}
