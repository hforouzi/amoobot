<?php

declare(strict_types=1);

namespace App\MiniApp\Admin;

use App\Security\AdminPermission;

/**
 * Which permission each Mini App admin route needs (/app/admin/** pages, /app/api/admin/** calls), enforced on every
 * request by MiniAppAdminGuard. Fail-closed: an admin route missing here is refused to everyone (and a test fails),
 * as AdminCrudPermissions does for the web panel.
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

            'miniapp_admin_payments' => AdminPermission::PAYMENTS_VIEW,
            'miniapp_admin_payment' => AdminPermission::PAYMENTS_VIEW,
            'miniapp_admin_payment_receipt' => AdminPermission::PAYMENTS_VIEW,
            'miniapp_admin_payment_approve' => AdminPermission::PAYMENTS_MODERATE,
            'miniapp_admin_payment_retry' => AdminPermission::PAYMENTS_MODERATE,
            'miniapp_admin_payment_reject' => AdminPermission::PAYMENTS_MODERATE,
            'miniapp_admin_payment_reject_refunded' => AdminPermission::PAYMENTS_MODERATE,

            'miniapp_admin_orders' => AdminPermission::ORDERS_VIEW,
            'miniapp_admin_order' => AdminPermission::ORDERS_VIEW,

            'miniapp_admin_customers' => AdminPermission::CUSTOMERS_VIEW,
            'miniapp_admin_customer' => AdminPermission::CUSTOMERS_VIEW,

            'miniapp_admin_services' => AdminPermission::SERVICES_VIEW,
            'miniapp_admin_service' => AdminPermission::SERVICES_VIEW,
            'miniapp_admin_service_resend_config' => AdminPermission::SERVICES_ASSIST,
            'miniapp_admin_service_sync_usage' => AdminPermission::SERVICES_ASSIST,
            'miniapp_admin_service_suspend' => AdminPermission::SERVICES_MANAGE,
            'miniapp_admin_service_activate' => AdminPermission::SERVICES_MANAGE,
            'miniapp_admin_service_extend' => AdminPermission::SERVICES_MANAGE,
            'miniapp_admin_service_add_traffic' => AdminPermission::SERVICES_MANAGE,
            'miniapp_admin_service_reset_usage' => AdminPermission::SERVICES_MANAGE,
            // As on the web (VpnServiceCrudController): regenerating configs is a panel operation.
            'miniapp_admin_service_regenerate_config' => AdminPermission::PANELS_MANAGE,
            'miniapp_admin_service_delete' => AdminPermission::SERVICES_DELETE,

            // Catalog (M3b): plans, trial plans and the bulk price change need PLANS_MANAGE, as on the web
            // (AdminCrudPermissions); there is no separate view permission.
            'miniapp_admin_plans' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_new' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_create' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_update' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_check' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_duplicate' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_activate' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_deactivate' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_delete' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_bulk' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_bulk_preview' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_plan_bulk_apply' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_trial_plans' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_trial_plan_new' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_trial_plan' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_trial_plan_create' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_trial_plan_update' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_trial_plan_check' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_trial_plan_activate' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_trial_plan_deactivate' => AdminPermission::PLANS_MANAGE,
            'miniapp_admin_trial_claims' => AdminPermission::PLANS_MANAGE,

            'miniapp_admin_discounts' => AdminPermission::DISCOUNTS_MANAGE,
            'miniapp_admin_discount_new' => AdminPermission::DISCOUNTS_MANAGE,
            'miniapp_admin_discount' => AdminPermission::DISCOUNTS_MANAGE,
            'miniapp_admin_discount_usages' => AdminPermission::DISCOUNTS_MANAGE,
            'miniapp_admin_discount_create' => AdminPermission::DISCOUNTS_MANAGE,
            'miniapp_admin_discount_update' => AdminPermission::DISCOUNTS_MANAGE,
            'miniapp_admin_discount_check' => AdminPermission::DISCOUNTS_MANAGE,
            'miniapp_admin_discount_generate' => AdminPermission::DISCOUNTS_MANAGE,
            'miniapp_admin_discount_activate' => AdminPermission::DISCOUNTS_MANAGE,
            'miniapp_admin_discount_deactivate' => AdminPermission::DISCOUNTS_MANAGE,
            'miniapp_admin_discount_delete' => AdminPermission::DISCOUNTS_MANAGE,

            // Operations (M3c).
            'miniapp_admin_broadcasts' => AdminPermission::BROADCAST_SEND,
            'miniapp_admin_broadcast_new' => AdminPermission::BROADCAST_SEND,
            'miniapp_admin_broadcast' => AdminPermission::BROADCAST_SEND,
            'miniapp_admin_broadcast_create' => AdminPermission::BROADCAST_SEND,
            'miniapp_admin_broadcast_update' => AdminPermission::BROADCAST_SEND,
            'miniapp_admin_broadcast_check' => AdminPermission::BROADCAST_SEND,
            'miniapp_admin_broadcast_test' => AdminPermission::BROADCAST_SEND,
            'miniapp_admin_broadcast_prepare' => AdminPermission::BROADCAST_SEND,
            'miniapp_admin_broadcast_send' => AdminPermission::BROADCAST_SEND,
            'miniapp_admin_broadcast_cancel' => AdminPermission::BROADCAST_SEND,
            'miniapp_admin_broadcast_retry' => AdminPermission::BROADCAST_SEND,
            // Panels: the super admin's, as on the web (no credential, URL or token is changed here).
            'miniapp_admin_panels' => AdminPermission::PANELS_MANAGE,
            'miniapp_admin_panel' => AdminPermission::PANELS_MANAGE,
            'miniapp_admin_panel_test' => AdminPermission::PANELS_MANAGE,
            'miniapp_admin_panel_activate' => AdminPermission::PANELS_MANAGE,
            'miniapp_admin_panel_deactivate' => AdminPermission::PANELS_MANAGE,
            // Settings and required channels: the super admin's, as on the web.
            'miniapp_admin_settings' => AdminPermission::SETTINGS_MANAGE,
            'miniapp_admin_settings_sales' => AdminPermission::SETTINGS_MANAGE,
            'miniapp_admin_settings_automation' => AdminPermission::SETTINGS_MANAGE,
            'miniapp_admin_channels' => AdminPermission::SETTINGS_MANAGE,
            'miniapp_admin_channel_create' => AdminPermission::SETTINGS_MANAGE,
            'miniapp_admin_channel_activate' => AdminPermission::SETTINGS_MANAGE,
            'miniapp_admin_channel_deactivate' => AdminPermission::SETTINGS_MANAGE,
            'miniapp_admin_channel_delete' => AdminPermission::SETTINGS_MANAGE,
            // Client apps and downloads (ADMIN has it too, as on the web).
            'miniapp_admin_apps' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_client_app' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_client_app_update' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_client_app_check' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_client_app_activate' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_client_app_deactivate' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_client_app_move' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_download_new' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_download' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_download_create' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_download_update' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_download_check' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_download_activate' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_download_deactivate' => AdminPermission::APPS_MANAGE,
            'miniapp_admin_download_move' => AdminPermission::APPS_MANAGE,
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
