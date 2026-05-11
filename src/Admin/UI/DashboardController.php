<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\UI\Crud\BotMessageLogCrudController;
use App\Admin\UI\Crud\OrderCrudController;
use App\Admin\UI\Crud\PaymentCrudController;
use App\Admin\UI\Crud\PlanCrudController;
use App\Admin\UI\Crud\ServiceNotificationLogCrudController;
use App\Admin\UI\Crud\SettingCrudController;
use App\Admin\UI\Crud\TelegramAccountCrudController;
use App\Admin\UI\Crud\UserCrudController;
use App\Admin\UI\Crud\VpnInboundCrudController;
use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Admin\UI\Crud\VpnServiceCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('Amoobot Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-users');
        yield MenuItem::linkTo(TelegramAccountCrudController::class, 'Telegram Accounts', 'fa fa-paper-plane');
        yield MenuItem::linkTo(VpnPanelCrudController::class, 'VPN Panels', 'fa fa-server');
        yield MenuItem::linkTo(VpnInboundCrudController::class, 'VPN Inbounds', 'fa fa-globe');
        yield MenuItem::linkTo(PlanCrudController::class, 'Plans', 'fa fa-list');
        yield MenuItem::linkTo(OrderCrudController::class, 'Orders', 'fa fa-shopping-cart');
        yield MenuItem::linkTo(PaymentCrudController::class, 'Payments', 'fa fa-credit-card');
        yield MenuItem::linkTo(VpnServiceCrudController::class, 'VPN Services', 'fa fa-link');
        yield MenuItem::linkTo(ServiceNotificationLogCrudController::class, 'Service Notifications', 'fa fa-bell');
        yield MenuItem::linkTo(BotMessageLogCrudController::class, 'Bot Logs', 'fa fa-file-text');
        yield MenuItem::linkTo(SettingCrudController::class, 'Settings', 'fa fa-cog');
        yield MenuItem::linkToRoute('تنظیمات تمدید و قیمتگذاری', 'fa fa-sliders', 'admin_renewal_pricing_settings');
        yield MenuItem::linkToRoute('تغییر گروهی قیمت پلنها', 'fa fa-percent', 'admin_bulk_plan_price_adjustment');
    }
}
