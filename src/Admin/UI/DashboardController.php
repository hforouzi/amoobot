<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\Application\CardReceiptQueue;
use App\Admin\Application\GlobalAdminSearch;
use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI\Crud\AdminAuditLogCrudController;
use App\Admin\UI\Crud\AdminUserCrudController;
use App\Admin\UI\Crud\AppDownloadCrudController;
use App\Admin\UI\Crud\BotMessageTemplateCrudController;
use App\Admin\UI\Crud\BroadcastMessageCrudController;
use App\Admin\UI\Crud\DiscountCodeCrudController;
use App\Admin\UI\Crud\OrderCrudController;
use App\Admin\UI\Crud\PaymentCrudController;
use App\Admin\UI\Crud\PaymentGatewayCrudController;
use App\Admin\UI\Crud\PlanCrudController;
use App\Admin\UI\Crud\PluginCrudController;
use App\Admin\UI\Crud\UserCrudController;
use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Admin\UI\Crud\VpnServiceCrudController;
use App\Admin\UI\Dashboard\DashboardOverview;
use App\Admin\UI\Shell\AdminBrand;
use App\Admin\UI\Shell\AdminSections;
use App\Admin\UI\Shell\AdminUiMode;
use App\Admin\UI\Shell\SectionMenuItemMatcher;
use App\Entity\AdminUser;
use App\Payment\Application\Repair\StuckPaymentFinder;
use App\Security\AdminAccessPolicy;
use App\Security\AdminActor;
use App\Security\AdminPermission;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Locale;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Menu\ControllerMenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Menu\MenuItemInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly DashboardOverview $overview,
        private readonly AdminUiMode $uiMode,
        private readonly AdminSections $sections,
        private readonly AdminBrand $brand,
        private readonly CardReceiptQueue $cardReceipts,
    ) {
    }

    /**
     * The overview: what needs attention, today's sales, services, panels and shortcuts, per role (DashboardOverview).
     */
    public function index(): Response
    {
        $user = $this->getUser();
        if ($user instanceof AdminUser && !$user->isActive()) {
            throw $this->createAccessDeniedException('Inactive admin account.');
        }

        if ($this->uiMode->isModern() && $user instanceof AdminUser) {
            return $this->render('admin/modern/dashboard.html.twig', ['overview' => $this->overview->dashboard(AdminActor::fromAdminUser($user))]);
        }

        return $this->render('admin/dashboard.html.twig', [
            'admin_user' => $user,
            'overview' => $user instanceof AdminUser ? $this->overview->for(AdminActor::fromAdminUser($user)) : null,
        ]);
    }

    #[Route('/admin/search', name: 'admin_search', methods: ['GET'])]
    public function search(Request $request, GlobalAdminSearch $search): Response
    {
        if (!$this->uiMode->isModern() || !($user = $this->getUser()) instanceof AdminUser) {
            throw $this->createNotFoundException();
        }
        if (!$user->isActive()) {
            throw $this->createAccessDeniedException('Inactive admin account.');
        }
        $query = trim($request->query->getString('q'));
        return $this->render('admin/modern/search.html.twig', ['query' => $query, 'results' => $search->search(AdminActor::fromAdminUser($user), $query)]);
    }

    #[Route('/admin/payment-worklist', name: 'admin_payment_worklist', methods: ['GET'])]
    public function undelivered(Request $request, StuckPaymentFinder $finder, ClockInterface $clock, AdminAccessPolicy $policy): Response
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser || !$policy->can(AdminActor::fromAdminUser($user), AdminPermission::PAYMENTS_VIEW)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->uiMode->isModern()) {
            throw $this->createNotFoundException();
        }
        $qb = $finder->query($clock->now()->modify('-30 minutes'));
        // Keep the age/type constraints in the stuck branch; failures are actionable immediately.
        $conditions = (string) $qb->getDQLPart('where');
        $qb->where('('.$conditions.') OR p.status = :provisioningFailed')->setParameter('provisioningFailed', 'provisioning_failed');
        $page = max(1, min(100000, $request->query->getInt('page', 1)));
        $rows = $qb->select('p.id, p.amount, p.status')->orderBy('p.id', 'ASC')->setFirstResult(($page - 1) * 100)->setMaxResults(101)->getQuery()->getArrayResult();
        $more = count($rows) > 100;
        return $this->render('admin/modern/undelivered.html.twig', ['payments' => array_slice($rows, 0, 100), 'page' => $page, 'more' => $more]);
    }

    /**
     * The name in the top bar: the display name, else the whole email (never cut to its first letter, admin.css).
     */
    public function configureUserMenu(UserInterface $user): UserMenu
    {
        $menu = parent::configureUserMenu($user);
        if ($user instanceof AdminUser) {
            $menu->setName($user->getDisplayName() ?? $user->getEmail());
        }

        return $menu;
    }

    /** Defaults of every admin list and page: one date format everywhere. */
    public function configureCrud(): Crud
    {
        return Crud::new()
            ->setDateTimeFormat('yyyy-MM-dd HH:mm')
            ->setDateFormat('yyyy-MM-dd');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            // Modern: the brand name instead of "Amoobot Admin" (decision 9). EasyAdmin prints the title as HTML.
            ->setTitle($this->uiMode->isModern() ? htmlspecialchars($this->brand->name(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'Amoobot Admin')
            ->setTranslationDomain('admin')
            // Labels a CRUD does not set are translation keys too (AdminTranslationIdGenerator).
            ->useEntityTranslations()
            ->setLocales([
                Locale::new('fa', 'فارسی'),
                Locale::new('en', 'English'),
            ]);
    }

    public function configureAssets(): Assets
    {
        $assets = Assets::new()->addCssFile('assets/admin/admin.css')->addJsFile('assets/admin/admin.js');
        if ($this->uiMode->isModern()) {
            // The tokens and shell of the redesign (classic: exactly the two files above). The Stimulus controllers
            // are an ES module, loaded by the modern layout.
            $assets->addCssFile('assets/admin/admin-modern.css');
        }

        return $assets;
    }

    /**
     * Groups only; one entry per page. Pages reached from another page are linked from there instead of the menu:
     * order drafts (orders), discount usages (discount codes), trial plans and claims and bulk price adjustment
     * (plans), broadcast deliveries (a broadcast), the payment status tabs (payments), Telegram accounts (customers),
     * store payment methods (payment gateways), inbounds (their panel), and the tabs of Bot texts, Apps, Settings
     * and Logs. Each entry needs the permission of its page (AdminCrudPermissions, enforced server-side); a group is
     * shown when at least one entry is.
     */
    public function configureMenuItems(): iterable
    {
        if ($this->uiMode->isModern()) {
            yield from $this->modernMenuItems();

            return;
        }

        yield MenuItem::linkToDashboard('admin.dashboard', 'fa fa-home');

        $groups = [
            ['admin.menu.sales', 'fa fa-shopping-cart', [
                $this->crudLink(OrderCrudController::class, 'admin.orders', 'fa fa-shopping-cart'),
                $this->crudLink(PaymentCrudController::class, 'admin.payments', 'fa fa-credit-card'),
                $this->crudLink(DiscountCodeCrudController::class, 'admin.discount_codes', 'fa fa-ticket'),
            ]],
            ['admin.menu.catalog', 'fa fa-list', [
                $this->crudLink(PlanCrudController::class, 'admin.plans', 'fa fa-list'),
                // App downloads and client apps: two tabs of one page (AdminTabs).
                $this->crudLink(AppDownloadCrudController::class, 'admin.apps', 'fa fa-mobile'),
            ]],
            ['admin.menu.customers', 'fa fa-users', [
                $this->crudLink(UserCrudController::class, 'admin.customers', 'fa fa-users'),
                $this->crudLink(VpnServiceCrudController::class, 'admin.vpn_services', 'fa fa-link'),
                MenuItem::linkToRoute('admin.wallets', 'fa fa-money', 'admin_wallets')->setPermission(AdminCrudPermissions::view(WalletAdminController::class)),
                MenuItem::linkToRoute('admin.resellers', 'fa fa-handshake-o', 'admin_resellers')->setPermission(AdminCrudPermissions::view(ResellerAdminController::class)),
                MenuItem::linkToRoute('admin.claims', 'fa fa-gift', 'admin_claims')->setPermission(AdminCrudPermissions::view(ClaimAdminController::class)),
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
        yield MenuItem::linkToLogout('admin.logout', 'fa fa-sign-out');
    }

    /**
     * The modern sidebar: exactly the 7 sections (AdminSections). Each item opens the first page of its section the
     * admin may open, with that page's permission; a section with none is left out. "Sales & payments" carries the
     * card-to-card receipts badge (decision 5). Logout is at the bottom of the sidebar, next to the admin's name.
     *
     * @return iterable<MenuItemInterface>
     */
    private function modernMenuItems(): iterable
    {
        foreach (AdminSections::SECTIONS as $section => [$label, $icon]) {
            if (AdminSections::DASHBOARD === $section) {
                $item = MenuItem::linkToDashboard($label, $icon);
            } else {
                $page = $this->sections->landing($section);
                if (null === $page) {
                    continue;
                }
                $item = (null === $page['route'] ? MenuItem::linkTo($page['controller'], $label, $icon) : MenuItem::linkToRoute($label, $icon, $page['route']))
                    ->setPermission(AdminSections::permission($page['controller'], $page['route']));
                if (AdminSections::SALES === $section) {
                    $receipts = $this->cardReceipts->cachedCount();
                    if ($receipts > 0) {
                        $item->setBadge($receipts, 'danger', ['data-admin-badge' => 'card-receipts']);
                    }
                }
            }
            $item->getAsDto()->setHtmlAttribute(SectionMenuItemMatcher::ATTRIBUTE, $section);

            yield $item;
        }
    }

    /**
     * @param class-string $controller
     */
    private function crudLink(string $controller, string $label, string $icon): ControllerMenuItem
    {
        return MenuItem::linkTo($controller, $label, $icon)->setPermission(AdminCrudPermissions::view($controller));
    }
}
