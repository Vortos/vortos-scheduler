<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalRequest;
use Vortos\Scheduler\Security\Approval\ApprovalStatus;
use Vortos\Scheduler\Security\Approval\FourEyesApprovalStoreInterface;
use Vortos\Scheduler\Security\Exception\FourEyesApprovalRequiredException;
use Vortos\Scheduler\Security\Exception\SelfApprovalException;

/**
 * 4-eyes approval gate for sensitive scheduled operations.
 *
 * Governs two actions: Activate and RunNow on schedules marked sensitive=true.
 * requestApproval() is idempotent — if a pending request already exists for the
 * same (schedule, action) pair, it is returned without creating a duplicate.
 */
final class FourEyesGate implements FourEyesGateInterface
{
    public function __construct(
        private readonly FourEyesApprovalStoreInterface $store,
        private readonly ClockInterface                  $clock,
        private readonly int                             $approvalTtlSec = 86400,
    ) {}

    public function requiresApproval(Schedule $schedule): bool
    {
        return $schedule->sensitive;
    }

    /**
     * Idempotent: if a pending request already exists, returns it unchanged.
     */
    public function requestApproval(
        Schedule      $schedule,
        ApprovalAction $action,
        string        $requestedBy,
        ?string       $reason = null,
    ): ApprovalRequest {
        $existing = $this->store->findPending($schedule->id, $action);
        if ($existing !== null) {
            return $existing;
        }

        $now     = $this->clock->now();
        $request = new ApprovalRequest(
            id:          UuidV7::generate(),
            scheduleId:  $schedule->id,
            action:      $action,
            status:      ApprovalStatus::Pending,
            requestedBy: $requestedBy,
            requestedAt: $now,
            expiresAt:   $now->modify("+{$this->approvalTtlSec} seconds"),
            reason:      $reason,
            resolvedBy:  null,
            resolvedAt:  null,
        );

        $this->store->save($request);

        return $request;
    }

    public function approve(string $approvalId, string $approvedBy): ApprovalRequest
    {
        $request = $this->loadPending($approvalId);

        if ($request->requestedBy === $approvedBy) {
            throw new SelfApprovalException($approvalId, $approvedBy);
        }

        $approved = $request->withResolution(ApprovalStatus::Approved, $approvedBy, $this->clock->now());
        $this->store->save($approved);

        return $approved;
    }

    public function reject(string $approvalId, string $rejectedBy): ApprovalRequest
    {
        $request  = $this->loadPending($approvalId);
        $rejected = $request->withResolution(ApprovalStatus::Rejected, $rejectedBy, $this->clock->now());
        $this->store->save($rejected);

        return $rejected;
    }

    /**
     * Asserts that a valid (non-expired, approved) request exists for the given
     * (schedule, action) pair. Called before executing a gated operation.
     *
     * @throws FourEyesApprovalRequiredException if no approved request exists
     */
    public function assertApproved(Schedule $schedule, ApprovalAction $action): void
    {
        if (!$schedule->sensitive) {
            return;
        }

        $request = $this->store->findPending($schedule->id, $action);

        if ($request === null) {
            throw FourEyesApprovalRequiredException::notRequested($schedule->id->toString(), $action);
        }

        if ($request->isExpiredAt($this->clock->now())) {
            throw FourEyesApprovalRequiredException::expired($request->id);
        }

        if (!$request->isApproved()) {
            throw FourEyesApprovalRequiredException::notApproved(
                $schedule->id->toString(),
                $action,
                $request->status,
            );
        }
    }

    private function loadPending(string $approvalId): ApprovalRequest
    {
        $request = $this->store->findById($approvalId);

        if ($request === null) {
            throw new \InvalidArgumentException(
                sprintf('Approval request "%s" not found.', $approvalId),
            );
        }

        if ($request->status->isTerminal()) {
            throw FourEyesApprovalRequiredException::alreadyResolved($approvalId, $request->status);
        }

        if ($request->isExpiredAt($this->clock->now())) {
            throw FourEyesApprovalRequiredException::expired($approvalId);
        }

        return $request;
    }
}
