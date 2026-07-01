<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security\Approval;

use Vortos\Scheduler\Schedule\ScheduleId;

interface FourEyesApprovalStoreInterface
{
    public function save(ApprovalRequest $request): void;

    public function findById(string $id): ?ApprovalRequest;

    public function findPending(ScheduleId $scheduleId, ApprovalAction $action): ?ApprovalRequest;

    /** @return list<ApprovalRequest> */
    public function findBySchedule(ScheduleId $scheduleId): array;

    public function expireStaleBefore(\DateTimeImmutable $cutoff): int;

    /**
     * Return all pending (non-expired) approval requests, optionally filtered by tenant.
     *
     * Used by the Admin UI approval list screen (S10) to show all outstanding requests.
     * tenantId = null → return pending requests for all tenants + system schedules.
     * tenantId = 'x' → return pending requests for tenant 'x' only.
     *
     * @return list<ApprovalRequest>
     */
    public function findAllPending(?string $tenantId = null): array;
}
