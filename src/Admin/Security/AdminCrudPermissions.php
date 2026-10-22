<?php

declare(strict_types=1);

namespace App\Admin\Security;

use App\Admin\Routing\LegacyAdminRoutes;
use App\Admin\UI\Crud;
use App\Admin\UI\PanelSetupController;
use App\Admin\UI\PaymentModerationController;
use App\Admin\UI\SettingsAdminController;
use App\Admin\UI\VpnPanelInboundActionsController;
use App\Security\AdminPermission;

/**
 * Which permission each admin page needs (web). Enforced by AdminPermissionListener (every action of the controller,
 * including EasyAdmin's autocomplete/batch routes and the controller's own #[Route]s) and by
 * AdminCrudActionPermissionSubscriber (new/edit/delete). A CRUD controller missing here is closed to everyone.
 *
 * Who has which permission: App\Security\AdminRolePermissions.
 */
final class AdminCrudPermissions
{
    /**
     * Routes whose permission differs from their controller's (checked first). The old URLs of moved pages
     * (LegacyAdminRoutes) need the permission of the page they lead to.
     */
    private const ROUTES = [
        'admin_bulk_plan_price_adjustment' => AdminPermission::PLANS_MANAGE,
    ];

    /** Dashboard, login/logout, locale switch: any admin (access_control ^/admin). */
    private const OPEN = [
        \App\Admin\UI\DashboardController::class,
        \App\Admin\UI\AdminSecurityController::class,
        \App\Admin\UI\AdminLocaleController::class,
    ];

    /**
     * @return array<class-string, CrudPermission>
     */
    private static function controllers(): array
    {
        $p = static fn (AdminPermission $view, ?AdminPermission $manage = null, ?AdminPermission $delete = null): CrudPermission => new CrudPermission($view, $manage ?? $view, $delete ?? $manage ?? $view);

        return [
            Crud\OrderCrudController::class => $p(AdminPermission::ORDERS_VIEW, AdminPermission::ORDERS_MANAGE),
            Crud\OrderDraftCrudController::class => $p(AdminPermission::ORDERS_VIEW, AdminPermission::ORDERS_MANAGE),
            Crud\PaymentCrudController::class => $p(AdminPermission::PAYMENTS_VIEW, AdminPermission::PAYMENTS_MODERATE),
            PaymentModerationController::class => $p(AdminPermission::PAYMENTS_MODERATE),
            Crud\DiscountCodeCrudController::class => $p(AdminPermission::DISCOUNTS_MANAGE),
            Crud\DiscountUsageCrudController::class => $p(AdminPermission::DISCOUNTS_MANAGE),
            Crud\PlanCrudController::class => $p(AdminPermission::PLANS_MANAGE),
            // Apps page: downloads and client apps (two tabs, AdminTabs).
            Crud\AppDownloadCrudController::class => $p(AdminPermission::APPS_MANAGE),
            Crud\ClientAppCrudController::class => $p(AdminPermission::APPS_MANAGE),
            Crud\TrialPlanCrudController::class => $p(AdminPermission::PLANS_MANAGE),
            Crud\TrialClaimCrudController::class => $p(AdminPermission::PLANS_MANAGE),
            // Only the bulk price adjustment is left here (PLANS_MANAGE, ROUTES); settings moved to SettingsAdminController.
            \App\Admin\UI\RenewalPricingAdminController::class => $p(AdminPermission::SETTINGS_MANAGE),
            Crud\UserCrudController::class => $p(AdminPermission::CUSTOMERS_VIEW, AdminPermission::CUSTOMERS_MANAGE),
            Crud\TelegramAccountCrudController::class => $p(AdminPermission::CUSTOMERS_VIEW, AdminPermission::CUSTOMERS_MANAGE),
            Crud\VpnServiceCrudController::class => $p(AdminPermission::SERVICES_VIEW, AdminPermission::SERVICES_MANAGE, AdminPermission::SERVICES_DELETE),
            // "New subscription page link" of a service.
            \App\Admin\UI\SubscriptionPageLinkController::class => $p(AdminPermission::SERVICES_MANAGE),
            Crud\BroadcastMessageCrudController::class => $p(AdminPermission::BROADCAST_SEND),
            Crud\BroadcastDeliveryCrudController::class => $p(AdminPermission::BROADCAST_SEND),
            \App\Admin\UI\BroadcastActionsController::class => $p(AdminPermission::BROADCAST_SEND),
            Crud\BotMessageTemplateCrudController::class => $p(AdminPermission::BOT_TEXTS_MANAGE),
            Crud\BotButtonLabelCrudController::class => $p(AdminPermission::BOT_TEXTS_MANAGE),
            SettingsAdminController::class => $p(AdminPermission::SETTINGS_MANAGE),
            Crud\SettingCrudController::class => $p(AdminPermission::SETTINGS_MANAGE),
            Crud\RequiredChannelCrudController::class => $p(AdminPermission::SETTINGS_MANAGE),
            Crud\PaymentGatewayCrudController::class => $p(AdminPermission::GATEWAYS_MANAGE),
            Crud\ZibalPaymentGatewayCrudController::class => $p(AdminPermission::GATEWAYS_MANAGE),
            Crud\NowPaymentsPaymentGatewayCrudController::class => $p(AdminPermission::GATEWAYS_MANAGE),
            Crud\CustomApiPaymentGatewayCrudController::class => $p(AdminPermission::GATEWAYS_MANAGE),
            Crud\StorePaymentMethodCrudController::class => $p(AdminPermission::GATEWAYS_MANAGE),
            Crud\VpnPanelCrudController::class => $p(AdminPermission::PANELS_MANAGE),
            Crud\VpnInboundCrudController::class => $p(AdminPermission::PANELS_MANAGE),
            PanelSetupController::class => $p(AdminPermission::PANELS_MANAGE),
            // Also VpnService "regenerate config" (its route lives here).
            VpnPanelInboundActionsController::class => $p(AdminPermission::PANELS_MANAGE),
            Crud\PluginCrudController::class => $p(AdminPermission::PLUGINS_MANAGE),
            Crud\AdminUserCrudController::class => $p(AdminPermission::ADMINS_MANAGE),
            Crud\ServiceNotificationLogCrudController::class => $p(AdminPermission::LOGS_VIEW),
            Crud\BotMessageLogCrudController::class => $p(AdminPermission::LOGS_VIEW),
            Crud\AdminAuditLogCrudController::class => $p(AdminPermission::LOGS_VIEW),
        ];
    }

    public static function forController(string $controllerClass): ?CrudPermission
    {
        return self::controllers()[$controllerClass] ?? null;
    }

    public static function forRoute(string $routeName): ?AdminPermission
    {
        return self::ROUTES[$routeName] ?? LegacyAdminRoutes::permission($routeName);
    }

    public static function isOpen(string $controllerClass): bool
    {
        return in_array($controllerClass, self::OPEN, true);
    }

    /** The permission a menu entry / link to this controller's pages needs. */
    public static function view(string $controllerClass): string
    {
        return (self::forController($controllerClass) ?? throw new \LogicException($controllerClass.' has no admin permission.'))->view->value;
    }
}
