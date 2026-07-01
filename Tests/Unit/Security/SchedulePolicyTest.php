<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Engine\PolicyRegistry;
use Vortos\Authorization\Permission\PermissionRegistry;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Storage\NullAuthorizationVersionStore;
use Vortos\Authorization\Storage\NullEmergencyDenyList;
use Vortos\Authorization\Voter\RoleVoter;
use Vortos\Scheduler\Security\SchedulePolicy;
use Vortos\Scheduler\Security\SchedulerPermissionCatalog;
use Vortos\Scheduler\Security\SchedulerResourcePolicy;
use Vortos\Scheduler\Security\Exception\ScheduleAccessDeniedException;
use Vortos\Scheduler\Tests\Unit\Security\Support\ScheduleFactory;

/**
 * Integration-style unit test for SchedulePolicy.
 *
 * Uses a real PolicyEngine to verify the own→any→deny fallback chain.
 * PolicyEngine is final and untestable via mocks, so we construct it minimally.
 */
final class SchedulePolicyTest extends TestCase
{
    // ── assertCanCreate ───────────────────────────────────────────────────────

    public function test_allows_create_for_own_tenant_with_own_permission(): void
    {
        $policy   = new SchedulePolicy($this->engine(['ROLE_SCHEDULER_USER' => ['scheduler.create.own']]));
        $identity = new UserIdentity('user-1', ['ROLE_SCHEDULER_USER'], ['tenant_id' => 'tenant-1']);
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-1');

        $policy->assertCanCreate($identity, $schedule);
        $this->addToAssertionCount(1);
    }

    public function test_allows_create_for_admin_with_any_permission(): void
    {
        $policy   = new SchedulePolicy($this->engine(['ROLE_SCHEDULER_ADMIN' => ['scheduler.create.any']]));
        $identity = new UserIdentity('admin-1', ['ROLE_SCHEDULER_ADMIN'], ['tenant_id' => 'tenant-1']);
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-999'); // different tenant

        $policy->assertCanCreate($identity, $schedule);
        $this->addToAssertionCount(1);
    }

    public function test_falls_back_to_any_when_own_denied(): void
    {
        $policy = new SchedulePolicy($this->engine([
            'ROLE_SCHEDULER_ADMIN' => ['scheduler.create.own', 'scheduler.create.any'],
        ]));
        $identity = new UserIdentity('admin-1', ['ROLE_SCHEDULER_ADMIN'], ['tenant_id' => 'tenant-1']);
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-999'); // wrong tenant — .own denied

        // .own denied (tenant mismatch), .any granted → passes
        $policy->assertCanCreate($identity, $schedule);
        $this->addToAssertionCount(1);
    }

    public function test_throws_when_no_permissions(): void
    {
        $policy   = new SchedulePolicy($this->engine([]));
        $identity = new UserIdentity('user-1', [], ['tenant_id' => 'tenant-1']);
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-1');

        $this->expectException(ScheduleAccessDeniedException::class);
        $policy->assertCanCreate($identity, $schedule);
    }

    public function test_throws_when_own_permission_but_wrong_tenant(): void
    {
        $policy   = new SchedulePolicy($this->engine(['ROLE_SCHEDULER_USER' => ['scheduler.create.own']]));
        $identity = new UserIdentity('user-1', ['ROLE_SCHEDULER_USER'], ['tenant_id' => 'tenant-A']);
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-B');

        $this->expectException(ScheduleAccessDeniedException::class);
        $policy->assertCanCreate($identity, $schedule);
    }

    public function test_exception_carries_action_and_identity(): void
    {
        $policy   = new SchedulePolicy($this->engine([]));
        $identity = new UserIdentity('user-xyz', []);
        $schedule = ScheduleFactory::normal();

        try {
            $policy->assertCanCreate($identity, $schedule);
            self::fail('Expected ScheduleAccessDeniedException');
        } catch (ScheduleAccessDeniedException $e) {
            self::assertSame('scheduler.create', $e->action);
            self::assertSame('user-xyz', $e->identityId);
        }
    }

    // ── assertCanUpdate ───────────────────────────────────────────────────────

    public function test_allows_update_for_own_tenant(): void
    {
        $policy   = new SchedulePolicy($this->engine(['ROLE_SCHEDULER_USER' => ['scheduler.update.own']]));
        $identity = new UserIdentity('user-1', ['ROLE_SCHEDULER_USER'], ['tenant_id' => 'tenant-1']);
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-1');

        $policy->assertCanUpdate($identity, $schedule);
        $this->addToAssertionCount(1);
    }

    public function test_update_throws_when_denied(): void
    {
        $policy   = new SchedulePolicy($this->engine([]));
        $identity = new UserIdentity('user-1', []);

        $this->expectException(ScheduleAccessDeniedException::class);
        $policy->assertCanUpdate($identity, ScheduleFactory::normal());
    }

    // ── assertCanPause ────────────────────────────────────────────────────────

    public function test_allows_pause_for_own_tenant(): void
    {
        $policy   = new SchedulePolicy($this->engine(['ROLE_SCHEDULER_USER' => ['scheduler.pause.own']]));
        $identity = new UserIdentity('user-1', ['ROLE_SCHEDULER_USER'], ['tenant_id' => 'tenant-1']);
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-1');

        $policy->assertCanPause($identity, $schedule);
        $this->addToAssertionCount(1);
    }

    // ── assertCanDelete ───────────────────────────────────────────────────────

    public function test_allows_delete_for_admin(): void
    {
        $policy   = new SchedulePolicy($this->engine(['ROLE_SCHEDULER_ADMIN' => ['scheduler.delete.any']]));
        $identity = new UserIdentity('admin-1', ['ROLE_SCHEDULER_ADMIN']);
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-999');

        $policy->assertCanDelete($identity, $schedule);
        $this->addToAssertionCount(1);
    }

    // ── assertCanRunNow ───────────────────────────────────────────────────────

    public function test_allows_run_now_for_own_tenant(): void
    {
        $policy   = new SchedulePolicy($this->engine(['ROLE_SCHEDULER_USER' => ['scheduler.run-now.own']]));
        $identity = new UserIdentity('user-1', ['ROLE_SCHEDULER_USER'], ['tenant_id' => 'tenant-1']);
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-1');

        $policy->assertCanRunNow($identity, $schedule);
        $this->addToAssertionCount(1);
    }

    public function test_run_now_throws_when_denied(): void
    {
        $policy   = new SchedulePolicy($this->engine([]));
        $identity = new UserIdentity('user-1', []);

        $this->expectException(ScheduleAccessDeniedException::class);
        $policy->assertCanRunNow($identity, ScheduleFactory::normal());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, string[]> $rolePermissions role → permissions[]
     */
    private function engine(array $rolePermissions): PolicyEngine
    {
        $roleVoter          = new RoleVoter();
        $permissionRegistry = $this->buildPermissionRegistry();

        $resolver = new class ($roleVoter, $rolePermissions) implements PermissionResolverInterface {
            /** @param array<string, string[]> $rolePermissions */
            public function __construct(
                private readonly RoleVoter $roleVoter,
                private readonly array     $rolePermissions,
            ) {}

            public function resolve(UserIdentityInterface $identity): ResolvedPermissions
            {
                if (!$identity->isAuthenticated()) {
                    return ResolvedPermissions::empty($identity->id());
                }
                $expanded    = $this->roleVoter->expandRoleNames($identity->roles());
                $permissions = [];
                foreach ($expanded as $role) {
                    array_push($permissions, ...($this->rolePermissions[$role] ?? []));
                }
                return new ResolvedPermissions($identity->id(), $identity->roles(), $expanded, $permissions);
            }

            public function has(UserIdentityInterface $identity, string $permission): bool
            {
                return $this->resolve($identity)->has($permission);
            }
        };

        $policyRegistry = new PolicyRegistry(new ServiceLocator([
            'scheduler' => fn () => new SchedulerResourcePolicy(),
        ]));

        return new PolicyEngine(
            registry:          $policyRegistry,
            permissionRegistry: $permissionRegistry,
            resolver:          $resolver,
            denyList:          new NullEmergencyDenyList(),
            versionStore:      new NullAuthorizationVersionStore(),
            roleVoter:         $roleVoter,
            authzVersionCheck: false,
        );
    }

    private function buildPermissionRegistry(): PermissionRegistry
    {
        $perms = [];
        foreach (array_keys(SchedulerPermissionCatalog::grants()) as $perm) {
            [$resource, $action, $scope] = explode('.', $perm, 3);
            $perms[$perm] = [
                'permission'      => $perm,
                'resource'        => $resource,
                'action'          => $action,
                'scope'           => $scope,
                'label'           => $perm,
                'description'     => null,
                'dangerous'       => false,
                'bypassable'      => false,
                'policyRequired'  => str_ends_with($perm, '.own'),
                'selfEnforced'    => false,
                'group'           => 'Scheduler',
                'catalogClass'    => SchedulerPermissionCatalog::class,
            ];
        }
        return new PermissionRegistry($perms);
    }
}
