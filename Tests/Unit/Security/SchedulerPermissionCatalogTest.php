<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\Scheduler\Security\SchedulerPermissionCatalog;

final class SchedulerPermissionCatalogTest extends TestCase
{
    private const EXPECTED_PERMISSIONS = [
        'scheduler.create.own',
        'scheduler.create.any',
        'scheduler.update.own',
        'scheduler.update.any',
        'scheduler.pause.own',
        'scheduler.pause.any',
        'scheduler.delete.own',
        'scheduler.delete.any',
        'scheduler.run-now.own',
        'scheduler.run-now.any',
        'scheduler.retention.manage',
    ];

    public function test_all_eleven_permissions_are_declared(): void
    {
        $grants = SchedulerPermissionCatalog::grants();

        foreach (self::EXPECTED_PERMISSIONS as $permission) {
            self::assertArrayHasKey($permission, $grants, "Missing permission: {$permission}");
        }

        self::assertCount(11, $grants);
    }

    public function test_retention_manage_has_no_own_scope_and_is_admin_only(): void
    {
        $grants = SchedulerPermissionCatalog::grants();

        self::assertArrayNotHasKey('scheduler.retention.manage.own', $grants);
        self::assertNotContains('ROLE_SCHEDULER_USER', $grants['scheduler.retention.manage']);
        self::assertContains('ROLE_SCHEDULER_ADMIN', $grants['scheduler.retention.manage']);
        self::assertContains('ROLE_SUPER_ADMIN', $grants['scheduler.retention.manage']);
    }

    public function test_own_permissions_include_scheduler_user_role(): void
    {
        $grants = SchedulerPermissionCatalog::grants();

        $ownPermissions = array_filter(
            array_keys($grants),
            fn (string $p) => str_ends_with($p, '.own'),
        );

        foreach ($ownPermissions as $permission) {
            self::assertContains(
                'ROLE_SCHEDULER_USER',
                $grants[$permission],
                "ROLE_SCHEDULER_USER not granted for {$permission}",
            );
        }
    }

    public function test_any_permissions_do_not_include_scheduler_user_role(): void
    {
        $grants = SchedulerPermissionCatalog::grants();

        $anyPermissions = array_filter(
            array_keys($grants),
            fn (string $p) => str_ends_with($p, '.any'),
        );

        foreach ($anyPermissions as $permission) {
            self::assertNotContains(
                'ROLE_SCHEDULER_USER',
                $grants[$permission],
                "ROLE_SCHEDULER_USER must not be granted for {$permission}",
            );
        }
    }

    public function test_super_admin_gets_all_permissions(): void
    {
        $grants = SchedulerPermissionCatalog::grants();

        foreach (self::EXPECTED_PERMISSIONS as $permission) {
            self::assertContains(
                'ROLE_SUPER_ADMIN',
                $grants[$permission],
                "ROLE_SUPER_ADMIN not granted for {$permission}",
            );
        }
    }

    public function test_admin_gets_all_permissions(): void
    {
        $grants = SchedulerPermissionCatalog::grants();

        foreach (self::EXPECTED_PERMISSIONS as $permission) {
            self::assertContains(
                'ROLE_SCHEDULER_ADMIN',
                $grants[$permission],
                "ROLE_SCHEDULER_ADMIN not granted for {$permission}",
            );
        }
    }

    public function test_meta_covers_all_permissions(): void
    {
        $meta  = SchedulerPermissionCatalog::meta();
        $grants = SchedulerPermissionCatalog::grants();

        foreach (array_keys($grants) as $permission) {
            self::assertArrayHasKey($permission, $meta, "Missing meta for {$permission}");
        }
    }

    public function test_own_permissions_are_policy_required(): void
    {
        $meta = SchedulerPermissionCatalog::meta();

        $ownPermissions = array_filter(
            array_keys($meta),
            fn (string $p) => str_ends_with($p, '.own'),
        );

        foreach ($ownPermissions as $permission) {
            self::assertTrue(
                $meta[$permission]['policyRequired'] ?? false,
                "Permission {$permission} should be policyRequired=true",
            );
        }
    }

    public function test_has_permission_catalog_attribute(): void
    {
        $ref   = new \ReflectionClass(SchedulerPermissionCatalog::class);
        $attrs = $ref->getAttributes(PermissionCatalog::class);

        self::assertNotEmpty($attrs, 'SchedulerPermissionCatalog must carry #[PermissionCatalog]');
        self::assertSame('scheduler', $attrs[0]->newInstance()->resource);
    }

    public function test_run_now_permissions_are_marked_dangerous(): void
    {
        $meta = SchedulerPermissionCatalog::meta();

        self::assertTrue($meta['scheduler.run-now.own']['dangerous'] ?? false);
        self::assertTrue($meta['scheduler.run-now.any']['dangerous'] ?? false);
    }
}
