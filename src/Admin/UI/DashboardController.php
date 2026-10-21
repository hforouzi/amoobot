<?php

declare(strict_types=1);

namespace App\Admin\UI;

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
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Locale;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    private const SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

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
     * adjustment, broadcast deliveries) are linked from there instead of the menu. Super-admin entries match the
     * server-side #[IsGranted('ROLE_SUPER_ADMIN')] on their controllers.
     */
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('admin.dashboard', 'fa fa-home');

        yield MenuItem::section('admin.menu.sales', 'fa fa-shopping-cart');
        yield MenuItem::linkTo(OrderCrudController::class, 'admin.orders', 'fa fa-shopping-cart');
        yield MenuItem::linkTo(PaymentCrudController::class, 'admin.payments', 'fa fa-credit-card');
        yield MenuItem::subMenu('admin.menu.needs_attention', 'fa fa-exclamation-triangle')->setSubItems([
            MenuItem::linkTo(PaymentNeedsReviewCrudController::class, 'admin.payments_needs_review', 'fa fa-exclamation-triangle'),
            MenuItem::linkTo(PaymentProvisioningFailedCrudController::class, 'admin.payments_provisioning_failed', 'fa fa-exclamation-circle'),
        ]);
        yield MenuItem::linkTo(DiscountCodeCrudController::class, 'admin.discount_codes', 'fa fa-ticket');

        yield MenuItem::section('admin.menu.catalog', 'fa fa-list');
        yield MenuItem::linkTo(PlanCrudController::class, 'admin.plans', 'fa fa-list');

        yield MenuItem::section('admin.menu.customers', 'fa fa-users');
        yield MenuItem::linkTo(UserCrudController::class, 'admin.users', 'fa fa-users');
        yield MenuItem::linkTo(TelegramAccountCrudController::class, 'admin.telegram_accounts', 'fa fa-paper-plane');
        yield MenuItem::linkTo(VpnServiceCrudController::class, 'admin.vpn_services', 'fa fa-link');

        yield MenuItem::section('admin.menu.messaging', 'fa fa-commenting');
        yield MenuItem::linkTo(BroadcastMessageCrudController::class, 'admin.broadcasts', 'fa fa-bullhorn');
        yield MenuItem::linkTo(BotMessageTemplateCrudController::class, 'admin.bot_message_templates', 'fa fa-comment');
        yield MenuItem::linkTo(BotButtonLabelCrudController::class, 'admin.bot_button_labels', 'fa fa-keyboard-o');

        yield MenuItem::section('admin.menu.system', 'fa fa-cogs')->setPermission(self::SUPER_ADMIN);
        yield MenuItem::linkTo(PaymentGatewayCrudController::class, 'admin.payment_gateways', 'fa fa-exchange')->setPermission(self::SUPER_ADMIN);
        yield MenuItem::linkTo(StorePaymentMethodCrudController::class, 'admin.store_payment_methods', 'fa fa-list-ol')->setPermission(self::SUPER_ADMIN);
        yield MenuItem::linkTo(VpnPanelCrudController::class, 'admin.vpn_panels', 'fa fa-server')->setPermission(self::SUPER_ADMIN);
        yield MenuItem::linkTo(VpnInboundCrudController::class, 'admin.vpn_inbounds', 'fa fa-globe')->setPermission(self::SUPER_ADMIN);
        yield MenuItem::linkTo(SettingCrudController::class, 'admin.settings', 'fa fa-cog')->setPermission(self::SUPER_ADMIN);
        yield MenuItem::linkTo(PluginCrudController::class, 'admin.plugins', 'fa fa-plug')->setPermission(self::SUPER_ADMIN);
        yield MenuItem::linkTo(AdminUserCrudController::class, 'admin.admin_users', 'fa fa-user-secret')->setPermission(self::SUPER_ADMIN);
        yield MenuItem::linkTo(RequiredChannelCrudController::class, 'admin.required_channels', 'fa fa-bullhorn')->setPermission(self::SUPER_ADMIN);
        if ($this->routeExists('admin_renewal_pricing_settings')) {
            yield MenuItem::linkToRoute('admin.renewal_pricing_settings', 'fa fa-sliders', 'admin_renewal_pricing_settings')->setPermission(self::SUPER_ADMIN);
        }
        if ($this->routeExists('admin_automation_settings')) {
            yield MenuItem::linkToRoute('admin.automation_settings', 'fa fa-clock-o', 'admin_automation_settings')->setPermission(self::SUPER_ADMIN);
        }
        yield MenuItem::subMenu('admin.menu.logs', 'fa fa-file-text')->setPermission(self::SUPER_ADMIN)->setSubItems([
            MenuItem::linkTo(ServiceNotificationLogCrudController::class, 'admin.notifications', 'fa fa-bell')->setPermission(self::SUPER_ADMIN),
            MenuItem::linkTo(BotMessageLogCrudController::class, 'admin.bot_logs', 'fa fa-file-text')->setPermission(self::SUPER_ADMIN),
        ]);

        yield MenuItem::section();
        yield MenuItem::linkToLogout('خروج', 'fa fa-sign-out');
    }

    private function routeExists(string $routeName): bool
    {
        return null !== $this->router->getRouteCollection()->get($routeName);
    }
}
