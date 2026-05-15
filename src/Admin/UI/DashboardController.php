<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\UI\Crud\BotMessageLogCrudController;
use App\Admin\UI\Crud\DiscountCodeCrudController;
use App\Admin\UI\Crud\DiscountUsageCrudController;
use App\Admin\UI\Crud\OrderCrudController;
use App\Admin\UI\Crud\OrderDraftCrudController;
use App\Admin\UI\Crud\PaymentCrudController;
use App\Admin\UI\Crud\PaymentGatewayCrudController;
use App\Admin\UI\Crud\PlanCrudController;
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
    public function __construct(
        private readonly RouterInterface $router,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
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

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('admin.dashboard', 'fa fa-home');

        yield MenuItem::section('admin.menu.store', 'fa fa-shopping-bag');
        yield MenuItem::linkTo(PlanCrudController::class, 'admin.plans', 'fa fa-list');
        yield MenuItem::linkTo(OrderCrudController::class, 'admin.orders', 'fa fa-shopping-cart');
        yield MenuItem::linkTo(OrderDraftCrudController::class, 'Order Drafts', 'fa fa-list-alt');
        yield MenuItem::linkTo(PaymentCrudController::class, 'admin.payments', 'fa fa-credit-card');
        yield MenuItem::linkTo(DiscountCodeCrudController::class, 'admin.discount_codes', 'fa fa-ticket');
        yield MenuItem::linkTo(DiscountUsageCrudController::class, 'admin.discount_usages', 'fa fa-bar-chart');
        yield MenuItem::linkTo(PaymentGatewayCrudController::class, 'admin.payment_gateways', 'fa fa-exchange');
        yield MenuItem::linkTo(StorePaymentMethodCrudController::class, 'admin.store_payment_methods', 'fa fa-list-ol');

        yield MenuItem::section('admin.menu.vpn', 'fa fa-server');
        yield MenuItem::linkTo(VpnPanelCrudController::class, 'admin.vpn_panels', 'fa fa-server');
        yield MenuItem::linkTo(VpnInboundCrudController::class, 'admin.vpn_inbounds', 'fa fa-globe');
        yield MenuItem::linkTo(VpnServiceCrudController::class, 'admin.vpn_services', 'fa fa-link');

        yield MenuItem::section('admin.menu.users', 'fa fa-users');
        yield MenuItem::linkTo(UserCrudController::class, 'admin.users', 'fa fa-users');
        yield MenuItem::linkTo(TelegramAccountCrudController::class, 'admin.telegram_accounts', 'fa fa-paper-plane');

        yield MenuItem::section('admin.menu.automation', 'fa fa-magic');
        yield MenuItem::linkTo(ServiceNotificationLogCrudController::class, 'admin.notifications', 'fa fa-bell');
        if ($this->routeExists('admin_renewal_pricing_settings')) {
            yield MenuItem::linkToRoute('admin.renewal_pricing_settings', 'fa fa-sliders', 'admin_renewal_pricing_settings');
        }
        if ($this->routeExists('admin_automation_settings')) {
            yield MenuItem::linkToRoute('admin.automation_settings', 'fa fa-clock-o', 'admin_automation_settings');
        }
        if ($this->routeExists('admin_bulk_plan_price_adjustment')) {
            yield MenuItem::linkToRoute('admin.bulk_plan_price_adjustment', 'fa fa-percent', 'admin_bulk_plan_price_adjustment');
        }

        yield MenuItem::section('admin.menu.system', 'fa fa-cogs');
        yield MenuItem::linkTo(SettingCrudController::class, 'admin.settings', 'fa fa-cog');
        yield MenuItem::linkTo(BotMessageLogCrudController::class, 'admin.bot_logs', 'fa fa-file-text');
    }

    private function routeExists(string $routeName): bool
    {
        return null !== $this->router->getRouteCollection()->get($routeName);
    }
}
