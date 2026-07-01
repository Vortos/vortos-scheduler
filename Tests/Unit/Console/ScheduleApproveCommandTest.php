<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Console;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Scheduler\Clock\MutableClock;
use Vortos\Scheduler\Console\ScheduleApproveCommand;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalRequest;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;
use Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface;
use Vortos\Scheduler\Security\Exception\SelfApprovalException;
use Vortos\Scheduler\Security\FourEyesGate;

/**
 * @covers \Vortos\Scheduler\Console\ScheduleApproveCommand
 */
final class ScheduleApproveCommandTest extends TestCase
{
    private function makeApprovalRequest(string $id, string $requestedBy = 'requester-1'): ApprovalRequest
    {
        return new ApprovalRequest(
            id:          $id,
            scheduleId:  ScheduleId::generate(),
            action:      ApprovalAction::Activate,
            status:      ApprovalStatus::Pending,
            requestedBy: $requestedBy,
            requestedAt: new DateTimeImmutable('2026-07-01T10:00:00+00:00'),
            expiresAt:   new DateTimeImmutable('2026-07-02T10:00:00+00:00'),
            reason:      null,
            resolvedBy:  null,
            resolvedAt:  null,
        );
    }

    private function makeFakeApprovalStore(?ApprovalRequest $request): FourEyesApprovalStoreInterface
    {
        return new class($request) implements FourEyesApprovalStoreInterface {
            public ?ApprovalRequest $saved = null;

            public function __construct(private ?ApprovalRequest $request) {}

            public function save(ApprovalRequest $req): void { $this->saved = $req; }

            public function findById(string $id): ?ApprovalRequest
            {
                return $this->request?->id === $id ? $this->request : null;
            }

            public function findPending(ScheduleId $scheduleId, ApprovalAction $action): ?ApprovalRequest
            {
                return null;
            }

            public function findBySchedule(ScheduleId $scheduleId): array
            {
                return $this->request !== null ? [$this->request] : [];
            }

            public function findAllPending(?string $tenantId = null): array { return $this->request !== null ? [$this->request] : []; }

            public function expireStaleBefore(DateTimeImmutable $cutoff): int { return 0; }
        };
    }

    private function makeGate(FourEyesApprovalStoreInterface $store): FourEyesGate
    {
        return new FourEyesGate(
            store:          $store,
            clock:          new MutableClock(new DateTimeImmutable('2026-07-01T12:00:00+00:00')),
            approvalTtlSec: 86400,
        );
    }

    public function test_approve_exits_zero_and_shows_approved_message(): void
    {
        $request = $this->makeApprovalRequest('req-123', 'requester-1');
        $store   = $this->makeFakeApprovalStore($request);
        $gate    = $this->makeGate($store);
        $command = new ScheduleApproveCommand($gate);
        $tester  = new CommandTester($command);

        $tester->execute([
            'approval-id' => 'req-123',
            '--actor'     => 'approver-1',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('approved', strtolower($tester->getDisplay()));
    }

    public function test_reject_flag_exits_zero_and_shows_rejected_message(): void
    {
        $request = $this->makeApprovalRequest('req-456', 'requester-1');
        $store   = $this->makeFakeApprovalStore($request);
        $gate    = $this->makeGate($store);
        $command = new ScheduleApproveCommand($gate);
        $tester  = new CommandTester($command);

        $tester->execute([
            'approval-id' => 'req-456',
            '--actor'     => 'rejector-1',
            '--reject'    => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('rejected', strtolower($tester->getDisplay()));
    }

    public function test_unknown_approval_id_exits_failure(): void
    {
        $store   = $this->makeFakeApprovalStore(null);
        $gate    = $this->makeGate($store);
        $command = new ScheduleApproveCommand($gate);
        $tester  = new CommandTester($command);

        $tester->execute([
            'approval-id' => 'no-such-request',
            '--actor'     => 'approver-1',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not found', strtolower($tester->getDisplay()));
    }

    public function test_self_approval_exits_failure(): void
    {
        // Same actor as requester
        $request = $this->makeApprovalRequest('req-789', 'operator-1');
        $store   = $this->makeFakeApprovalStore($request);
        $gate    = $this->makeGate($store);
        $command = new ScheduleApproveCommand($gate);
        $tester  = new CommandTester($command);

        $tester->execute([
            'approval-id' => 'req-789',
            '--actor'     => 'operator-1',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Self-approval', $tester->getDisplay());
    }

    public function test_approve_shows_resolver_actor_id(): void
    {
        $request = $this->makeApprovalRequest('req-abc', 'requester-1');
        $store   = $this->makeFakeApprovalStore($request);
        $gate    = $this->makeGate($store);
        $command = new ScheduleApproveCommand($gate);
        $tester  = new CommandTester($command);

        $tester->execute([
            'approval-id' => 'req-abc',
            '--actor'     => 'manager-1',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('manager-1', $tester->getDisplay());
    }
}
