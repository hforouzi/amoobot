<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Crud\AdminAuditLogCrudController;
use App\Admin\UI\Crud\AdminUserCrudController;
use App\Admin\UI\Crud\BroadcastMessageCrudController;
use App\Admin\UI\Crud\BotMessageTemplateCrudController;
use App\Admin\UI\Crud\DiscountCodeCrudController;
use App\Admin\UI\Crud\OrderCrudController;
use App\Admin\UI\Crud\PaymentCrudController;
use App\Admin\UI\Crud\PaymentGatewayCrudController;
use App\Admin\UI\Crud\PluginCrudController;
use App\Admin\UI\Crud\PlanCrudController;
use App\Admin\UI\Crud\UserCrudController;
use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Admin\UI\Crud\VpnServiceCrudController;
use App\Security\AdminPermission;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Locale;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Menu\ControllerMenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    /** Dashboard cards: the payment tabs an admin has to act on. */
    private const PAYMENT_CARDS = ['pending', 'needs_review', 'provisioning_failed'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function index(): Response
    {
        $user = $this->getUser();
        if ($user instanceof \App\Entity\AdminUser && !$user->isActive()) {
            throw $this->createAccessDeniedException('Inactive admin account.');
        }

        $paymentCards = [];
        if ($this->isGranted(AdminPermission::PAYMENTS_VIEW->value)) {
            $counts = PaymentCrudController::countByTab($this->entityManager);
            foreach (self::PAYMENT_CARDS as $tab) {
                $paymentCards[] = ['key' => $tab, 'label' => 'payment.tabs.'.$tab, 'count' => $counts[$tab], 'url' => $this->generateUrl('admin_payment_index', ['tab' => $tab])];
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'admin_user' => $user,
            'payment_cards' => $paymentCards,
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
     * Groups only; one entry per page. Pages reached from another page are linked from there instead of the menu:
     * order drafts (orders), discount usages (discount codes), trial plans and claims and bulk price adjustment
     * (plans), broadcast deliveries (a broadcast), the payment status tabs (payments), Telegram accounts (customers),
     * store payment methods (payment gateways), inbounds (their panel), and the tabs of Bot texts, Settings and
     * Logs. Each entry needs the permission of its page (AdminCrudPermissions, enforced server-side); a group is
     * shown when at least one entry is.
     */
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('admin.dashboard', 'fa fa-home');

        $groups = [
            ['admin.menu.sales', 'fa fa-shopping-cart', [
                $this->crudLink(OrderCrudController::class, 'admin.orders', 'fa fa-shopping-cart'),
                $this->crudLink(PaymentCrudController::class, 'admin.payments', 'fa fa-credit-card'),
                $this->crudLink(DiscountCodeCrudController::class, 'admin.discount_codes', 'fa fa-ticket'),
            ]],
            ['admin.menu.catalog', 'fa fa-list', [
                $this->crudLink(PlanCrudController::class, 'admin.plans', 'fa fa-list'),
            ]],
            ['admin.menu.customers', 'fa fa-users', [
                $this->crudLink(UserCrudController::class, 'admin.customers', 'fa fa-users'),
                $this->crudLink(VpnServiceCrudController::class, 'admin.vpn_services', 'fa fa-link'),
            ]],
            ['admin.menu.messaging', 'fa fa-commenting', [
                $this->crudLink(BroadcastMessageCrudController::class, 'admin.broadcasts', 'fa fa-bullhorn'),
                // Message templates and button labels: two tabs of one page (AdminTabs).
                $this->crudLink(BotMessageTemplateCrudController::class, 'admin.bot_texts', 'fa fa-comment'),
            ]],
            ['admin.menu.system', 'fa fa-cogs', [
                $this->crudLink(PaymentGatewayCrudController::class, 'admin.payment_gateways', 'fa fa-exchange'),
                $this->crudLink(VpnPanelCrudController::class, 'admin.vpn_panels', 'fa fa-server'),
                MenuItem::linkToRoute('admin.settings', 'fa fa-cog', 'admin_settings')->setPermission(AdminCrudPermissions::view(SettingsAdminController::class)),
                $this->crudLink(PluginCrudController::class, 'admin.plugins', 'fa fa-plug'),
                $this->crudLink(AdminUserCrudController::class, 'admin.admin_users', 'fa fa-user-secret'),
                // Audit, notification and bot message logs: three tabs of one page (AdminTabs).
                $this->crudLink(AdminAuditLogCrudController::class, 'admin.logs', 'fa fa-file-text'),
            ]],
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
}
