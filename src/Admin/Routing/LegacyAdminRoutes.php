<?php

declare(strict_types=1);

namespace App\Admin\Routing;

use App\Security\AdminPermission;

/**
 * Admin pages that moved (navigation consolidation): their old URLs redirect to the new place, so bookmarks and old
 * links keep working. Routes: config/routes/admin_legacy.php; served by LegacyAdminRedirectController.
 *
 * Each entry is a route: "from" (and, unless exact, everything below it: /{rest}) redirects to "to" (+ /{rest});
 * "query" is added when the old list itself (no {rest}) is opened. "aliases" keeps the old route names of a moved
 * EasyAdmin CRUD generating URLs: <old>_<action> is an alias of <new>_<action>. "permission" is checked before
 * redirecting (AdminCrudPermissions::forRoute), the same as the page it leads to.
 *
 * Lists folded into another page whose CRUD stays where it is (Telegram accounts, store payment methods, required
 * channels, settings) redirect from their CRUD's index() instead.
 */
final class LegacyAdminRoutes
{
    /** EasyAdmin's route name suffixes of one CRUD controller. */
    public const CRUD_ACTIONS = ['index', 'new', 'batch_delete', 'autocomplete', 'render_filters', 'edit', 'delete', 'detail'];

    /**
     * @var array<string, array{from: string, to: string, permission: AdminPermission, query?: array<string, string>, exact?: bool, aliases?: array<string, string>}>
     */
    public const REDIRECTS = [
        // Payments "needs attention" lists: tabs of the payment list now.
        'admin_payment_needs_review_index' => ['from' => '/admin/payment-needs-review', 'to' => '/admin/payment', 'query' => ['tab' => 'needs_review'], 'permission' => AdminPermission::PAYMENTS_VIEW, 'aliases' => ['admin_payment_needs_review' => 'admin_payment']],
        'admin_payment_provisioning_failed_index' => ['from' => '/admin/payment-provisioning-failed', 'to' => '/admin/payment', 'query' => ['tab' => 'provisioning_failed'], 'permission' => AdminPermission::PAYMENTS_VIEW, 'aliases' => ['admin_payment_provisioning_failed' => 'admin_payment']],
        // Users: the Customers page.
        'admin_legacy_user' => ['from' => '/admin/user', 'to' => '/admin/customer', 'permission' => AdminPermission::CUSTOMERS_VIEW, 'aliases' => ['admin_user' => 'admin_customer']],
        // Settings: tabs of /admin/settings.
        'admin_renewal_pricing_settings' => ['from' => '/admin/settings/renewal-pricing', 'to' => '/admin/settings', 'query' => ['tab' => 'sales'], 'exact' => true, 'permission' => AdminPermission::SETTINGS_MANAGE],
        'admin_automation_settings' => ['from' => '/admin/settings/automation', 'to' => '/admin/settings', 'query' => ['tab' => 'automation'], 'exact' => true, 'permission' => AdminPermission::SETTINGS_MANAGE],
        // Bot texts: one page, two tabs.
        'admin_legacy_bot_message_template' => ['from' => '/admin/bot-message-template', 'to' => '/admin/bot-texts/messages', 'permission' => AdminPermission::BOT_TEXTS_MANAGE, 'aliases' => ['admin_bot_message_template' => 'admin_bot_texts_messages']],
        'admin_legacy_bot_button_label' => ['from' => '/admin/bot-button-label', 'to' => '/admin/bot-texts/buttons', 'permission' => AdminPermission::BOT_TEXTS_MANAGE, 'aliases' => ['admin_bot_button_label' => 'admin_bot_texts_buttons']],
        // Logs: one page, three tabs.
        'admin_legacy_admin_audit_log' => ['from' => '/admin/admin-audit-log', 'to' => '/admin/logs/audit', 'permission' => AdminPermission::LOGS_VIEW, 'aliases' => ['admin_admin_audit_log' => 'admin_logs_audit']],
        'admin_legacy_service_notification_log' => ['from' => '/admin/service-notification-log', 'to' => '/admin/logs/notifications', 'permission' => AdminPermission::LOGS_VIEW, 'aliases' => ['admin_service_notification_log' => 'admin_logs_notifications']],
        'admin_legacy_bot_message_log' => ['from' => '/admin/bot-message-log', 'to' => '/admin/logs/bot-messages', 'permission' => AdminPermission::LOGS_VIEW, 'aliases' => ['admin_bot_message_log' => 'admin_logs_bot_messages']],
    ];

    public static function permission(string $routeName): ?AdminPermission
    {
        return (self::REDIRECTS[$routeName] ?? null)['permission'] ?? null;
    }
}
