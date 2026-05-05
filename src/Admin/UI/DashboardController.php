<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Entity\BotMessageLog;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\Setting;
use App\Entity\TelegramAccount;
use App\Entity\User;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
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
        yield MenuItem::linkToCrud('Users', 'fa fa-users', User::class);
        yield MenuItem::linkToCrud('Telegram Accounts', 'fa fa-paper-plane', TelegramAccount::class);
        yield MenuItem::linkToCrud('Plans', 'fa fa-list', Plan::class);
        yield MenuItem::linkToCrud('Orders', 'fa fa-shopping-cart', Order::class);
        yield MenuItem::linkToCrud('Payments', 'fa fa-credit-card', Payment::class);
        yield MenuItem::linkToCrud('VPN Panels', 'fa fa-server', VpnPanel::class);
        yield MenuItem::linkToCrud('VPN Services', 'fa fa-link', VpnService::class);
        yield MenuItem::linkToCrud('Bot Logs', 'fa fa-file-text', BotMessageLog::class);
        yield MenuItem::linkToCrud('Settings', 'fa fa-cog', Setting::class);
    }
}
