<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Service;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Scheduler\Audit\SchedulerAuditProjector;
use Vortos\Scheduler\Clock\ClockPort;
use Vortos\Scheduler\Engine\FireDispatcherPort;
use Vortos\Scheduler\Engine\FireDispatchResult;
use Vortos\Scheduler\Fire\ScheduledFire;
use Vortos\Scheduler\Registry\StaticScheduleRegistry;
use Vortos\Scheduler\Schedule\Schedule;
use Vortos\Scheduler\Schedule\ScheduleId;
use Vortos\Scheduler\Schedule\ScheduleSource;
use Vortos\Scheduler\Schedule\ScheduleStatus;
use Vortos\Scheduler\Security\Approval\ApprovalAction;
use Vortos\Scheduler\Security\Approval\ApprovalRequest;
use Vortos\Scheduler\Security\CommandSpecValidator;
use Vortos\Scheduler\Security\Exception\FourEyesApprovalRequiredException;
use Vortos\Scheduler\Security\FourEyesGate;
use Vortos\Scheduler\Security\SchedulePolicyInterface;
use Vortos\Scheduler\Store\Exception\ScheduleNotFoundException;
use Vortos\Scheduler\Store\ScheduleStatusOverride;
use Vortos\Scheduler\Store\ScheduleStatusOverrideStoreInterface;
use Vortos\Scheduler\Store\ScheduleStoreInterface;

/**
 * Domain service shared by CLI commands and the future Admin UI.
 *
 * Coordinates pause/resume (with static-override semantics), run-now (with
 * 4-eyes gate for sensitive schedules), and approval requests.
 *
 * Thread safety: this service is stateless — all state lives in stores.
 */
final class ScheduleService implements ScheduleServiceInterface
{
    public function __construct(
        private readonly StaticScheduleRegistry                $staticRegistry,
        private readonly ScheduleStoreInterface                $dynamicStore,
        private readonly ScheduleStatusOverrideStoreInterface  $overrideStore,
        private readonly SchedulePolicyInterface               $policy,
        private readonly ClockPort                             $clock,
        private readonly FireDispatcherPort                    $fireDispatcher,
        private readonly ?FourEyesGate                         $fourEyesGate = null,
        private readonly ?SchedulerAuditProjector              $audit = null,
        private readonly ?CommandSpecValidator                  $validator = null,
    ) {}

    /**
     * Create a new dynamic schedule (RBAC + allowlist validator + audit).
     *
     * @throws \Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException if not permitted
     * @throws \Vortos\Scheduler\Security\Exception\CommandNotAllowlistedException if command not allowlisted
     * @throws \Vortos\Scheduler\Store\Exception\ScheduleNameConflictException    if name already used
     */
    public function create(Schedule $schedule, UserIdentityInterface $actor): Schedule
    {
        $this->policy->assertCanCreate($actor, $schedule);
        $this->validator?->assert($schedule->command);
        $this->dynamicStore->save($schedule);
        $this->audit?->onScheduleCreated($schedule, $actor->id());
        return $schedule;
    }

    /**
     * Update an existing dynamic schedule (RBAC + allowlist validator + audit + CAS).
     *
     * The caller is responsible for passing a Schedule whose version matches the
     * currently-stored row (fetched via loadSchedule). The store will throw
     * OptimisticLockException if the row has been modified concurrently.
     *
     * @throws \Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException if not permitted
     * @throws \Vortos\Scheduler\Security\Exception\CommandNotAllowlistedException if command not allowlisted
     * @throws \DomainException if the schedule is static (static schedules cannot be edited at runtime)
     * @throws \Vortos\Scheduler\Store\Exception\OptimisticLockException on concurrent edit
     */
    public function update(Schedule $schedule, UserIdentityInterface $actor, ?string $reason = null): Schedule
    {
        if ($schedule->source === ScheduleSource::Static) {
            throw new \DomainException(sprintf(
                'Static schedule "%s" cannot be edited at runtime. Remove or update the #[Scheduled] attribute instead.',
                $schedule->name,
            ));
        }
        $this->policy->assertCanUpdate($actor, $schedule);
        $this->validator?->assert($schedule->command);
        $this->dynamicStore->save($schedule);
        $this->audit?->onScheduleUpdated($schedule, $actor->id(), $reason);
        return $schedule;
    }

    /**
     * Hard-delete a dynamic schedule (RBAC + audit).
     *
     * The run-ledger rows are NOT deleted — they remain as the permanent idempotency
     * and audit record. Static schedules cannot be deleted at runtime.
     *
     * @throws ScheduleNotFoundException if schedule not found
     * @throws \Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException if not permitted
     * @throws \DomainException if the schedule is static
     */
    public function delete(
        ScheduleId            $id,
        ?string               $tenantId,
        UserIdentityInterface $actor,
        ?string               $reason = null,
    ): void {
        $schedule = $this->loadSchedule($id, $tenantId);

        if ($schedule->source === ScheduleSource::Static) {
            throw new \DomainException(sprintf(
                'Static schedule "%s" cannot be deleted at runtime. Remove the #[Scheduled] attribute instead.',
                $schedule->name,
            ));
        }

        $this->policy->assertCanDelete($actor, $schedule);
        $this->dynamicStore->delete($id, $tenantId);
        $this->audit?->onScheduleDeleted($schedule, $actor->id(), $reason);
    }

    /**
     * Load a schedule by ID, applying any runtime override.
     *
     * Checks static registry first, then dynamic store.
     *
     * @throws ScheduleNotFoundException if no schedule found
     */
    public function loadSchedule(ScheduleId $id, ?string $tenantId): Schedule
    {
        // 1. Check static registry.
        $static = $this->staticRegistry->findById($id->toString());
        if ($static !== null) {
            // Apply runtime status override.
            $override = $this->overrideStore->find($id);
            if ($override !== null) {
                return $static->withStatus($override->status);
            }

            return $static;
        }

        // 2. Check dynamic store.
        $dynamic = $this->dynamicStore->find($id, $tenantId);
        if ($dynamic !== null) {
            return $dynamic;
        }

        throw new ScheduleNotFoundException($id->toString(), $tenantId);
    }

    /**
     * Pause a schedule. For static schedules, saves a runtime override row.
     * For dynamic schedules, updates the store.
     *
     * @throws ScheduleNotFoundException          if schedule not found
     * @throws \Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException if not permitted
     * @return Schedule the paused schedule
     */
    public function pause(
        ScheduleId            $id,
        ?string               $tenantId,
        UserIdentityInterface $actor,
        ?string               $reason = null,
    ): Schedule {
        $schedule = $this->loadSchedule($id, $tenantId);
        $this->policy->assertCanPause($actor, $schedule);

        if ($schedule->source === ScheduleSource::Static) {
            $this->overrideStore->save(
                new ScheduleStatusOverride($id, ScheduleStatus::Paused, $actor->id(), $reason, $this->clock->now()),
            );
            $result = $schedule->withStatus(ScheduleStatus::Paused);
        } else {
            $result = $schedule->withStatus(ScheduleStatus::Paused);
            $this->dynamicStore->save($result);
        }

        $this->audit?->onSchedulePaused($schedule, $actor->id(), $reason);

        return $result;
    }

    /**
     * Resume a paused schedule. For static schedules, removes the runtime override.
     * For dynamic schedules, updates the store.
     *
     * @throws ScheduleNotFoundException          if schedule not found
     * @throws \Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException if not permitted
     * @return Schedule the resumed schedule
     */
    public function resume(
        ScheduleId            $id,
        ?string               $tenantId,
        UserIdentityInterface $actor,
    ): Schedule {
        $schedule = $this->loadSchedule($id, $tenantId);
        $this->policy->assertCanUpdate($actor, $schedule);

        if ($schedule->source === ScheduleSource::Static) {
            $this->overrideStore->remove($id);
            $result = $schedule->withStatus(ScheduleStatus::Active);
        } else {
            $result = $schedule->withStatus(ScheduleStatus::Active);
            $this->dynamicStore->save($result);
        }

        $this->audit?->onScheduleResumed($schedule, $actor->id());

        return $result;
    }

    /**
     * Manually trigger a schedule to fire now (outside its normal trigger schedule).
     *
     * @throws ScheduleNotFoundException                 if schedule not found
     * @throws \Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException  if not permitted
     * @throws \DomainException                          if the schedule is disabled
     * @throws \LogicException                           if the schedule is sensitive but no FourEyesGate is wired
     * @throws FourEyesApprovalRequiredException         if approval is required but not yet granted
     */
    public function runNow(
        ScheduleId            $id,
        ?string               $tenantId,
        UserIdentityInterface $actor,
        ?string               $reason = null,
    ): FireDispatchResult {
        $schedule = $this->loadSchedule($id, $tenantId);
        $this->policy->assertCanRunNow($actor, $schedule);

        if ($schedule->status === ScheduleStatus::Disabled) {
            throw new \DomainException(sprintf(
                'Cannot run-now a disabled schedule "%s" (%s).',
                $schedule->name,
                $id->toString(),
            ));
        }

        if ($schedule->sensitive) {
            if ($this->fourEyesGate === null) {
                throw new \LogicException(
                    'Sensitive schedule requires FourEyesGate but none is registered. '
                    . 'Install vortos-authorization and wire FourEyesGate.',
                );
            }

            $this->fourEyesGate->assertApproved($schedule, ApprovalAction::RunNow);
        }

        $slot = 'manual:' . $this->clock->now()->format(\DateTimeInterface::ATOM) . ':' . bin2hex(random_bytes(4));

        $fire = new ScheduledFire(
            scheduleId:   $schedule->id,
            tenantId:     $schedule->tenantId,
            slot:         $slot,
            scheduledFor: $this->clock->now(),
            attempt:      1,
        );

        $result = $this->fireDispatcher->dispatch($fire, $schedule);

        $this->audit?->onManualFire($schedule, $actor->id(), $slot, $reason);

        return $result;
    }

    /**
     * Request 4-eyes approval for a schedule action.
     *
     * @throws ScheduleNotFoundException if schedule not found
     * @throws \LogicException           if no FourEyesGate is wired
     */
    public function requestApproval(
        ScheduleId            $id,
        ?string               $tenantId,
        ApprovalAction        $action,
        UserIdentityInterface $actor,
        ?string               $reason = null,
    ): ApprovalRequest {
        $schedule = $this->loadSchedule($id, $tenantId);

        if ($this->fourEyesGate === null) {
            throw new \LogicException(
                'FourEyesGate is not registered. Install vortos-authorization to use approval flows.',
            );
        }

        return $this->fourEyesGate->requestApproval($schedule, $action, $actor->id(), $reason);
    }
}
