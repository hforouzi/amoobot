<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\AdminUser;
use App\Security\AdminAccessPolicy;
use App\Security\AdminActor;
use App\Security\AdminPermission;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The role -> permission matrix, every role x every permission, checked against the specification written out here
 * (independent of App\Security\AdminRolePermissions).
 */
final class AdminAccessPolicyTest extends KernelTestCase
{
    private const SUPPORT = ['ORDERS_VIEW', 'PAYMENTS_VIEW', 'CUSTOMERS_VIEW', 'SERVICES_VIEW', 'SERVICES_ASSIST'];
    private const NOT_FOR_ADMIN = ['GATEWAYS_MANAGE', 'PANELS_MANAGE', 'PLUGINS_MANAGE', 'SETTINGS_MANAGE', 'ADMINS_MANAGE', 'LOGS_VIEW'];

    /**
     * @return iterable<string, array{string, AdminPermission, bool}>
     */
    public static function matrix(): iterable
    {
        foreach (AdminPermission::cases() as $permission) {
            yield AdminUser::ROLE_SUPER_ADMIN.' '.$permission->name => [AdminUser::ROLE_SUPER_ADMIN, $permission, true];
            yield AdminUser::ROLE_ADMIN.' '.$permission->name => [AdminUser::ROLE_ADMIN, $permission, !in_array($permission->name, self::NOT_FOR_ADMIN, true)];
            yield AdminUser::ROLE_SUPPORT.' '.$permission->name => [AdminUser::ROLE_SUPPORT, $permission, in_array($permission->name, self::SUPPORT, true)];
        }
    }

    #[DataProvider('matrix')]
    public function testRoleHasExactlyItsPermissions(string $role, AdminPermission $permission, bool $expected): void
    {
        $admin = (new AdminUser())->setEmail('m@shop.test')->setRoles([$role])->setIsActive(true);

        self::assertSame($expected, $this->policy()->can(AdminActor::fromAdminUser($admin), $permission));
    }

    public function testTheSpecificationNamesOnlyExistingPermissions(): void
    {
        foreach ([...self::SUPPORT, ...self::NOT_FOR_ADMIN] as $name) {
            self::assertNotNull(constant(AdminPermission::class.'::'.$name));
        }
    }

    public function testAnInactiveAdminHasNoPermission(): void
    {
        $admin = (new AdminUser())->setEmail('off@shop.test')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(false);
        $actor = AdminActor::fromAdminUser($admin);

        foreach (AdminPermission::cases() as $permission) {
            self::assertFalse($this->policy()->can($actor, $permission), $permission->name);
        }
        self::assertFalse($this->policy()->can(null, AdminPermission::ORDERS_VIEW), 'no actor, no permission');
    }

    public function testRolesAddUpAndTheAlwaysAddedRoleAdminDoesNotPromoteSupport(): void
    {
        // getRoles() always adds ROLE_ADMIN (for the /admin firewall rule); the policy uses the assigned roles only.
        $support = (new AdminUser())->setEmail('s@shop.test')->setRoles([AdminUser::ROLE_SUPPORT])->setIsActive(true);
        self::assertContains(AdminUser::ROLE_ADMIN, $support->getRoles());
        self::assertFalse($this->policy()->can(AdminActor::fromAdminUser($support), AdminPermission::PLANS_MANAGE));

        $both = (new AdminUser())->setEmail('b@shop.test')->setRoles([AdminUser::ROLE_SUPPORT, AdminUser::ROLE_ADMIN])->setIsActive(true);
        self::assertTrue($this->policy()->can(AdminActor::fromAdminUser($both), AdminPermission::PLANS_MANAGE));
    }

    public function testTheEnvironmentBotAdminIsASuperAdmin(): void
    {
        $actor = AdminActor::environmentBotAdmin('999000');

        foreach (AdminPermission::cases() as $permission) {
            self::assertTrue($this->policy()->can($actor, $permission), $permission->name);
        }
    }

    private function policy(): AdminAccessPolicy
    {
        self::bootKernel();

        return self::getContainer()->get(AdminAccessPolicy::class);
    }
}
