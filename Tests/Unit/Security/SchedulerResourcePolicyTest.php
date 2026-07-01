<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Authorization\Attribute\AsPolicy;
use Vortos\Authorization\Context\AuthorizationContext;
use Vortos\Authorization\Decision\PolicyDecision;
use Vortos\Authorization\Permission\ResolvedPermissions;
use Vortos\Authorization\Voter\RoleVoter;
use Vortos\Scheduler\Security\SchedulerResourcePolicy;
use Vortos\Scheduler\Tests\Unit\Security\Support\ScheduleFactory;

final class SchedulerResourcePolicyTest extends TestCase
{
    private SchedulerResourcePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SchedulerResourcePolicy();
    }

    // ── .any scope ────────────────────────────────────────────────────────────

    public function test_allows_any_scope_regardless_of_tenant(): void
    {
        $auth     = $this->auth('user-1', 'tenant-1');
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-999');

        $result = $this->policy->can($auth, 'create', 'any', $schedule);

        self::assertTrue($this->toBool($result));
    }

    public function test_allows_any_scope_with_null_resource(): void
    {
        $auth   = $this->auth('user-1', 'tenant-1');
        $result = $this->policy->can($auth, 'delete', 'any', null);

        self::assertTrue($this->toBool($result));
    }

    // ── .own scope ────────────────────────────────────────────────────────────

    public function test_allows_own_scope_when_tenant_matches(): void
    {
        $auth     = $this->auth('user-1', 'tenant-1');
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-1');

        $result = $this->policy->can($auth, 'create', 'own', $schedule);

        self::assertTrue($this->toBool($result));
    }

    public function test_denies_own_scope_when_tenant_differs(): void
    {
        $auth     = $this->auth('user-1', 'tenant-1');
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-999');

        $result = $this->policy->can($auth, 'create', 'own', $schedule);

        self::assertFalse($this->toBool($result));
    }

    public function test_denies_own_scope_when_resource_is_null(): void
    {
        $auth   = $this->auth('user-1', 'tenant-1');
        $result = $this->policy->can($auth, 'create', 'own', null);

        self::assertFalse($this->toBool($result));
    }

    public function test_denies_own_scope_when_resource_is_not_schedule(): void
    {
        $auth   = $this->auth('user-1', 'tenant-1');
        $result = $this->policy->can($auth, 'create', 'own', new \stdClass());

        self::assertFalse($this->toBool($result));
    }

    public function test_denies_own_scope_when_user_has_no_tenant_id(): void
    {
        $auth     = $this->authWithoutTenant('user-1');
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-1');

        $result = $this->policy->can($auth, 'create', 'own', $schedule);

        self::assertFalse($this->toBool($result));
    }

    public function test_denies_system_schedule_for_own_scope(): void
    {
        $auth     = $this->auth('user-1', 'tenant-1');
        $schedule = ScheduleFactory::normal(tenantId: null); // system schedule has no tenant

        $result = $this->policy->can($auth, 'update', 'own', $schedule);

        self::assertFalse($this->toBool($result));
    }

    public function test_denial_is_policy_decision_not_bool(): void
    {
        $auth     = $this->auth('user-1', 'tenant-1');
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-999');

        $result = $this->policy->can($auth, 'create', 'own', $schedule);

        self::assertInstanceOf(PolicyDecision::class, $result);
        self::assertFalse($result->allowed);
    }

    public function test_allows_delete_own_for_correct_tenant(): void
    {
        $auth     = $this->auth('user-1', 'tenant-abc');
        $schedule = ScheduleFactory::normal(tenantId: 'tenant-abc');

        $result = $this->policy->can($auth, 'delete', 'own', $schedule);

        self::assertTrue($this->toBool($result));
    }

    // ── attribute ─────────────────────────────────────────────────────────────

    public function test_has_as_policy_attribute_for_scheduler_resource(): void
    {
        $ref   = new \ReflectionClass(SchedulerResourcePolicy::class);
        $attrs = $ref->getAttributes(AsPolicy::class);

        self::assertNotEmpty($attrs, 'Must carry #[AsPolicy(resource: "scheduler")]');
        self::assertSame('scheduler', $attrs[0]->newInstance()->resource);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function auth(string $userId, string $tenantId): AuthorizationContext
    {
        $identity = new UserIdentity($userId, [], ['tenant_id' => $tenantId]);

        return new AuthorizationContext(
            $identity,
            new ResolvedPermissions($userId, [], [], []),
            new RoleVoter(),
        );
    }

    private function authWithoutTenant(string $userId): AuthorizationContext
    {
        $identity = new UserIdentity($userId, []); // no attributes = no tenant_id

        return new AuthorizationContext(
            $identity,
            new ResolvedPermissions($userId, [], [], []),
            new RoleVoter(),
        );
    }

    private function toBool(bool|PolicyDecision $result): bool
    {
        return is_bool($result) ? $result : $result->allowed;
    }
}
