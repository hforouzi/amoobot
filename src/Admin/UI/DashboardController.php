<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Crud\AdminUserCrudController;
use App\Admin\UI\Crud\BotMessageLogCrudController;
use App\Admin\UI\Crud\BroadcastMessageCrudController;
use App\Admin\UI\Crud\BotButtonLabelCrudController;
use App\Admin\UI\Crud\BotMessageTemplateCrudController;
use App\Admin\UI\Crud\DiscountCodeCrudController;
use App\Admin\UI\Crud\OrderCrudController;
use App\Admin\UI\Crud\PaymentCrudController;
use App\Admin\UI\Crud\PaymentNeedsReviewCrudController;
use App\Admin\UI\Crud\PaymentProvisioningFailedCrudController;
use App\Admin\UI\Crud\PaymentGatewayCrudController;
use App\Admin\UI\Crud\PluginCrudController;
use App\Admin\UI\Crud\PlanCrudController;
use App\Admin\UI\Crud\RequiredChannelCrudController;
use App\Admin\UI\Crud\ServiceNotificationLogCrudController;
use App\Admin\UI\Crud\SettingCrudController;
use App\Admin\UI\Crud\StorePaymentMethodCrudController;
use App\Admin\UI\Crud\TelegramAccountCrudController;
use App\Admin\UI\Crud\UserCrudController;
use App\Admin\UI\Crud\VpnInboundCrudController;
use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Admin\UI\Crud\VpnServiceCrudController;
use App\Security\AdminPermission;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Locale;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Menu\ControllerMenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly RouterInterface $router,
    ) {
    }

    public function index(): Response
    {
        $user = $this->getUser();
        if ($user instanceof \App\Entity\AdminUser && !$user->isActive()) {
            throw $this->createAccessDeniedException('Inactive admin account.');
        }

        return $this->render('admin/dashboard.html.twig', [
            'admin_user' => $user,
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Amoobot Admin')
            ->setTranslationDomain('admin')
            ->setLocales([
                Locale::new('fa', 'فارسی'),
                Locale::new('en', 'English'),
            ]);
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('assets/admin/admin.css');
    }

    /**
     * Groups only; pages reached from another page (order drafts, discount usages, trial plans and claims, bulk price
     * adjustment, broadcast deliveries) are linked from there instead of the menu. Each entry needs the permission of
     * its page (AdminCrudPermissions, enforced server-side); a group is shown when at least one entry is.
     */
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('admin.dashboard', 'fa fa-home');

        $settings = AdminPermission::SETTINGS_MANAGE->value;
        $groups = [
            ['admin.menu.sales', 'fa fa-shopping-cart', [
                $this->crudLink(OrderCrudController::class, 'admin.orders', 'fa fa-shopping-cart'),
                $this->crudLink(PaymentCrudController::class, 'admin.payments', 'fa fa-credit-card'),
                MenuItem::subMenu('admin.menu.needs_attention', 'fa fa-exclamation-triangle')
                    ->setPermission(AdminCrudPermissions::view(PaymentNeedsReviewCrudController::class))
                    ->setSubItems([
                        $this->crudLink(PaymentNeedsReviewCrudController::class, 'admin.payments_needs_review', 'fa fa-exclamation-triangle'),
                        $this->crudLink(PaymentProvisioningFailedCrudController::class, 'admin.payments_provisioning_failed', 'fa fa-exclamation-circle'),
                    ]),
                $this->crudLink(DiscountCodeCrudController::class, 'admin.discount_codes', 'fa fa-ticket'),
            ]],
            ['admin.menu.catalog', 'fa fa-list', [
                $this->crudLink(PlanCrudController::class, 'admin.plans', 'fa fa-list'),
            ]],
            ['admin.menu.customers', 'fa fa-users', [
                $this->crudLink(UserCrudController::class, 'admin.users', 'fa fa-users'),
                $this->crudLink(TelegramAccountCrudController::class, 'admin.telegram_accounts', 'fa fa-paper-plane'),
                $this->crudLink(VpnServiceCrudController::class, 'admin.vpn_services', 'fa fa-link'),
            ]],
            ['admin.menu.messaging', 'fa fa-commenting', [
                $this->crudLink(BroadcastMessageCrudController::class, 'admin.broadcasts', 'fa fa-bullhorn'),
                $this->crudLink(BotMessageTemplateCrudController::class, 'admin.bot_message_templates', 'fa fa-comment'),
                $this->crudLink(BotButtonLabelCrudController::class, 'admin.bot_button_labels', 'fa fa-keyboard-o'),
            ]],
            ['admin.menu.system', 'fa fa-cogs', array_values(array_filter([
                $this->crudLink(PaymentGatewayCrudController::class, 'admin.payment_gateways', 'fa fa-exchange'),
                $this->crudLink(StorePaymentMethodCrudController::class, 'admin.store_payment_methods', 'fa fa-list-ol'),
                $this->crudLink(VpnPanelCrudController::class, 'admin.vpn_panels', 'fa fa-server'),
                $this->crudLink(VpnInboundCrudController::class, 'admin.vpn_inbounds', 'fa fa-globe'),
                $this->crudLink(SettingCrudController::class, 'admin.settings', 'fa fa-cog'),
                $this->crudLink(PluginCrudController::class, 'admin.plugins', 'fa fa-plug'),
                $this->crudLink(AdminUserCrudController::class, 'admin.admin_users', 'fa fa-user-secret'),
                $this->crudLink(RequiredChannelCrudController::class, 'admin.required_channels', 'fa fa-bullhorn'),
                $this->routeExists('admin_renewal_pricing_settings') ? MenuItem::linkToRoute('admin.renewal_pricing_settings', 'fa fa-sliders', 'admin_renewal_pricing_settings')->setPermission($settings) : null,
                $this->routeExists('admin_automation_settings') ? MenuItem::linkToRoute('admin.automation_settings', 'fa fa-clock-o', 'admin_automation_settings')->setPermission($settings) : null,
                MenuItem::subMenu('admin.menu.logs', 'fa fa-file-text')
                    ->setPermission(AdminCrudPermissions::view(ServiceNotificationLogCrudController::class))
                    ->setSubItems([
                        $this->crudLink(ServiceNotificationLogCrudController::class, 'admin.notifications', 'fa fa-bell'),
                        $this->crudLink(BotMessageLogCrudController::class, 'admin.bot_logs', 'fa fa-file-text'),
                    ]),
            ]))],
        ];

        foreach ($groups as [$label, $icon, $items]) {
            $visible = array_filter($items, fn (MenuItemInterface $item): bool => $this->isGranted((string) $item->getAsDto()->getPermission()));
            if ([] === $visible) {
                continue;
            }
            yield MenuItem::section($label, $icon);
            foreach ($visible as $item) {
                yield $item;
            }
        }

        yield MenuItem::section();
        yield MenuItem::linkToLogout('خروج', 'fa fa-sign-out');
    }

    /**
     * @param class-string $controller
     */
    private function crudLink(string $controller, string $label, string $icon): ControllerMenuItem
    {
        return MenuItem::linkTo($controller, $label, $icon)->setPermission(AdminCrudPermissions::view($controller));
    }

    private function routeExists(string $routeName): bool
    {
        return null !== $this->router->getRouteCollection()->get($routeName);
    }
}
