<?php

declare(strict_types=1);

namespace App\MiniApp\Admin;

use App\Security\AdminPermission;

/**
 * Which permission each Mini App admin route needs (/app/admin/** pages, /app/api/admin/** calls), enforced on every
 * request by MiniAppAdminGuard. Fail-closed: an admin route missing here is refused to everyone (and a test fails),
 * as AdminCrudPermissions does for the web panel. Later areas (catalog, broadcast, panels, settings, logs) add their
 * routes here.
 *
 * Who has which permission: App\Security\AdminRolePermissions.
 */
final class AdminRoutePermissions
{
    /** Any active admin (at least one permission): the admin home. */
    public const ANY_ADMIN = 'any_admin';

    /** Path prefixes of the admin area: every route under them must be listed in routes(). */
    public const PREFIXES = ['/app/admin', '/app/api/admin'];

    /**
     * Route name => the permission it needs.
     *
     * @return array<string, AdminPermission|string> a permission, or ANY_ADMIN
     */
    public static function routes(): array
    {
        return [
            'miniapp_admin' => self::ANY_ADMIN,
        ];
    }

    public static function isAdminPath(string $path): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return AdminPermission|string|null a permission, ANY_ADMIN, or null: not mapped (refused)
     */
    public static function forRoute(string $route): AdminPermission|string|null
    {
        return self::routes()[$route] ?? null;
    }
}
