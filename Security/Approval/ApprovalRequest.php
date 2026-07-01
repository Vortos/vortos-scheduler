<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Approval;

use DateTimeImmutable;
use Vortos\Scheduler\Schedule\ScheduleId;

final readonly class ApprovalRequest
{
    public function __construct(
        public string            $id,
        public ScheduleId        $scheduleId,
        public ApprovalAction    $action,
        public ApprovalStatus    $status,
        public string            $requestedBy,
        public DateTimeImmutable $requestedAt,
        public DateTimeImmutable $expiresAt,
        public ?string           $reason,
        public ?string           $resolvedBy,
        public ?DateTimeImmutable $resolvedAt,
    ) {}

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === ApprovalStatus::Approved;
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->status === ApprovalStatus::Pending && $this->expiresAt <= $now;
    }

    public function withResolution(
        ApprovalStatus   $status,
        string           $resolvedBy,
        DateTimeImmutable $resolvedAt,
    ): self {
        return new self(
            id:          $this->id,
            scheduleId:  $this->scheduleId,
            action:      $this->action,
            status:      $status,
            requestedBy: $this->requestedBy,
            requestedAt: $this->requestedAt,
            expiresAt:   $this->expiresAt,
            reason:      $this->reason,
            resolvedBy:  $resolvedBy,
            resolvedAt:  $resolvedAt,
        );
    }
}
