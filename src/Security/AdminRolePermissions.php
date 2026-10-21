<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\AdminUser;

/**
 * The role -> permission matrix: the single place that decides what each admin role may do (web and bot).
 * An admin with several roles has the union of their permissions.
 */
final class AdminRolePermissions
{
    /**
     * @return list<AdminPermission>
     */
    public static function forRole(string $role): array
    {
        return match ($role) {
            AdminUser::ROLE_SUPER_ADMIN => AdminPermission::cases(),
            // Everything except system configuration; includes APPS_MANAGE (client apps and app downloads).
            AdminUser::ROLE_ADMIN => self::allExcept(
                AdminPermission::GATEWAYS_MANAGE,
                AdminPermission::PANELS_MANAGE,
                AdminPermission::PLUGINS_MANAGE,
                AdminPermission::SETTINGS_MANAGE,
                AdminPermission::ADMINS_MANAGE,
                AdminPermission::LOGS_VIEW,
            ),
            // Read-only, plus the service actions that do not change what the customer paid for; no money actions.
            AdminUser::ROLE_SUPPORT => [
                AdminPermission::ORDERS_VIEW,
                AdminPermission::PAYMENTS_VIEW,
                AdminPermission::CUSTOMERS_VIEW,
                AdminPermission::SERVICES_VIEW,
                AdminPermission::SERVICES_ASSIST,
            ],
            default => [],
        };
    }

    /**
     * @return list<AdminPermission>
     */
    private static function allExcept(AdminPermission ...$excluded): array
    {
        $permissions = [];
        foreach (AdminPermission::cases() as $permission) {
            if (!in_array($permission, $excluded, true)) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }
}
