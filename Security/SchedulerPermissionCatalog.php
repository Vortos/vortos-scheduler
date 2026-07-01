<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Security;

use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\Authorization\Permission\AbstractPermissionCatalog;

/**
 * Declares all scheduler permissions and their default role assignments.
 *
 * Permissions follow the resource.action.scope format.
 * .own scope requires SchedulerResourcePolicy to verify tenantId ownership.
 * .any scope is admin-only; no resource policy check is needed.
 */
#[PermissionCatalog(resource: 'scheduler', group: 'Scheduler')]
final class SchedulerPermissionCatalog extends AbstractPermissionCatalog
{
    public static function grants(): array
    {
        return [
            'scheduler.create.own'  => ['ROLE_SCHEDULER_USER', 'ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
            'scheduler.create.any'  => ['ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
            'scheduler.update.own'  => ['ROLE_SCHEDULER_USER', 'ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
            'scheduler.update.any'  => ['ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
            'scheduler.pause.own'   => ['ROLE_SCHEDULER_USER', 'ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
            'scheduler.pause.any'   => ['ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
            'scheduler.delete.own'  => ['ROLE_SCHEDULER_USER', 'ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
            'scheduler.delete.any'  => ['ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
            'scheduler.run-now.own' => ['ROLE_SCHEDULER_USER', 'ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
            'scheduler.run-now.any' => ['ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
            // No .own scope: retention policy is a compliance/operator decision about
            // how long a tenant's audit trail must survive, not something a tenant
            // should be able to unilaterally shorten or extend for themselves.
            'scheduler.retention.manage' => ['ROLE_SCHEDULER_ADMIN', 'ROLE_SUPER_ADMIN'],
        ];
    }

    public static function meta(): array
    {
        return [
            'scheduler.create.own'  => static::policyRequired('Create own-tenant schedule'),
            'scheduler.create.any'  => static::describe('Create schedule for any tenant', dangerous: true),
            'scheduler.update.own'  => static::policyRequired('Update own-tenant schedule'),
            'scheduler.update.any'  => static::describe('Update schedule for any tenant', dangerous: true),
            'scheduler.pause.own'   => static::policyRequired('Pause own-tenant schedule'),
            'scheduler.pause.any'   => static::describe('Pause schedule for any tenant', dangerous: true),
            'scheduler.delete.own'  => static::policyRequired('Delete own-tenant schedule'),
            'scheduler.delete.any'  => static::describe('Delete schedule for any tenant', dangerous: true),
            'scheduler.run-now.own' => static::policyRequired('Trigger immediate run for own-tenant schedule', dangerous: true),
            'scheduler.run-now.any' => static::describe('Trigger immediate run for any schedule', dangerous: true),
            'scheduler.retention.manage' => static::describe('Set or remove a tenant\'s run-retention override', dangerous: true),
        ];
    }
}
