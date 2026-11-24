<?php

declare(strict_types=1);

namespace App\Admin\UI\Shell;

use App\Admin\Security\AdminCrudPermissions;
use App\Admin\UI;
use App\Admin\UI\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The 7 sections of the modern sidebar (docs/design/admin-redesign.md, 1 and 2) and which section every admin page
 * belongs to. Nothing moves: each page keeps its route and permission, the section only decides which sidebar item
 * is lit and which section tabs (the pages of the section the admin may open) are shown above the page.
 *
 * A section's sidebar item opens its first page the admin may open, with that page's permission
 * (AdminCrudPermissions), so the menu and the server can't disagree; a section with no such page has no item.
 *
 * @phpstan-type Page array{key: string, label: string, controller: class-string, route: string|null}
 * @phpstan-type Tab array{key: string, label: string, url: string, active: bool}
 */
final class AdminSections
{
    public const DASHBOARD = 'dashboard';
    public const SALES = 'sales';
    public const CUSTOMERS = 'customers';
    public const SERVICES = 'services';
    public const CATALOG = 'catalog';
    public const MESSAGING = 'messaging';
    public const SETTINGS = 'settings';

    /** The sidebar, in order: section => [label, icon]. Exactly the 7 items of the approved mockup. */
    public const SECTIONS = [
        self::DASHBOARD => ['admin_ui.nav.dashboard', 'fa fa-home'],
        self::SALES => ['admin_ui.nav.sales', 'fa fa-credit-card'],
        self::CUSTOMERS => ['admin_ui.nav.customers', 'fa fa-users'],
        self::SERVICES => ['admin_ui.nav.services', 'fa fa-link'],
        self::CATALOG => ['admin_ui.nav.catalog', 'fa fa-tags'],
        self::MESSAGING => ['admin_ui.nav.messaging', 'fa fa-commenting'],
        self::SETTINGS => ['admin_ui.nav.settings', 'fa fa-cog'],
    ];

    /**
     * The section tabs: the pages each section lists, in order (the first one the admin may open is where the sidebar
     * item leads). key => [label, controller, route]; a null route is the CRUD controller's list.
     */
    private const PAGES = [
        self::SALES => [
            'payments' => ['admin.payments', Crud\PaymentCrudController::class, null],
            'orders' => ['admin.orders', Crud\OrderCrudController::class, null],
            'order_drafts' => ['admin.order_drafts', Crud\OrderDraftCrudController::class, null],
        ],
        self::CUSTOMERS => [
            'customers' => ['admin.customers', Crud\UserCrudController::class, null],
            'wallets' => ['admin.wallets', UI\WalletAdminController::class, 'admin_wallets'],
            'resellers' => ['admin.resellers', UI\ResellerAdminController::class, 'admin_resellers'],
            'claims' => ['admin.claims', UI\ClaimAdminController::class, 'admin_claims'],
        ],
        self::SERVICES => [
            'services' => ['admin.vpn_services', Crud\VpnServiceCrudController::class, null],
        ],
        self::CATALOG => [
            'plans' => ['admin.plans', Crud\PlanCrudController::class, null],
            'discount_codes' => ['admin.discount_codes', Crud\DiscountCodeCrudController::class, null],
            'discount_usages' => ['admin.discount_usages', Crud\DiscountUsageCrudController::class, null],
            'trial_plans' => ['admin.trial_plans', Crud\TrialPlanCrudController::class, null],
            'trial_claims' => ['admin.trial_claims', Crud\TrialClaimCrudController::class, null],
            'bulk_price' => ['admin.bulk_plan_price_adjustment', UI\RenewalPricingAdminController::class, 'admin_bulk_plan_price_adjustment'],
        ],
        self::MESSAGING => [
            'broadcasts' => ['admin.broadcasts', Crud\BroadcastMessageCrudController::class, null],
            // Message templates and button labels (and the guide texts, an action of the list): one page, AdminTabs.
            'bot_texts' => ['admin.bot_texts', Crud\BotMessageTemplateCrudController::class, null],
            // Downloads and client apps: one page, AdminTabs.
            'apps' => ['admin.apps', Crud\AppDownloadCrudController::class, null],
        ],
        self::SETTINGS => [
            'settings' => ['admin.settings', UI\SettingsAdminController::class, 'admin_settings'],
            // Scheduled jobs (AD3): modern only, like the section tabs themselves.
            'jobs' => ['ad3.jobs.title', UI\SystemHealthController::class, 'admin_system_jobs'],
            'payment_gateways' => ['admin.payment_gateways', Crud\PaymentGatewayCrudController::class, null],
            'vpn_panels' => ['admin.vpn_panels', Crud\VpnPanelCrudController::class, null],
            'plugins' => ['admin.plugins', Crud\PluginCrudController::class, null],
            'admin_users' => ['admin.admin_users', Crud\AdminUserCrudController::class, null],
            // Audit, notification and bot message logs: one page, AdminTabs.
            'logs' => ['admin.logs', Crud\AdminAuditLogCrudController::class, null],
        ],
    ];

    /** Every admin controller => its section (pages that are not a section tab are reached from one that is). */
    private const CONTROLLERS = [
        UI\DashboardController::class => self::DASHBOARD,

        Crud\PaymentCrudController::class => self::SALES,
        UI\PaymentModerationController::class => self::SALES,
        Crud\OrderCrudController::class => self::SALES,
        Crud\OrderDraftCrudController::class => self::SALES,

        Crud\UserCrudController::class => self::CUSTOMERS,
        Crud\TelegramAccountCrudController::class => self::CUSTOMERS,
        UI\WalletAdminController::class => self::CUSTOMERS,
        UI\ResellerAdminController::class => self::CUSTOMERS,
        UI\ClaimAdminController::class => self::CUSTOMERS,

        Crud\VpnServiceCrudController::class => self::SERVICES,
        UI\SubscriptionPageLinkController::class => self::SERVICES,
        UI\ServiceDeleteController::class => self::SERVICES,
        UI\ServiceInboundsController::class => self::SERVICES,

        Crud\PlanCrudController::class => self::CATALOG,
        Crud\TrialPlanCrudController::class => self::CATALOG,
        Crud\TrialClaimCrudController::class => self::CATALOG,
        Crud\DiscountCodeCrudController::class => self::CATALOG,
        Crud\DiscountUsageCrudController::class => self::CATALOG,
        UI\InboundMoveAdminController::class => self::CATALOG,
        UI\RenewalPricingAdminController::class => self::CATALOG,

        Crud\BroadcastMessageCrudController::class => self::MESSAGING,
        Crud\BroadcastDeliveryCrudController::class => self::MESSAGING,
        UI\BroadcastActionsController::class => self::MESSAGING,
        Crud\BotMessageTemplateCrudController::class => self::MESSAGING,
        Crud\BotButtonLabelCrudController::class => self::MESSAGING,
        UI\GuideTextAdminController::class => self::MESSAGING,
        Crud\AppDownloadCrudController::class => self::MESSAGING,
        Crud\ClientAppCrudController::class => self::MESSAGING,

        UI\SettingsAdminController::class => self::SETTINGS,
        UI\SystemHealthController::class => self::SETTINGS,
        Crud\SettingCrudController::class => self::SETTINGS,
        Crud\RequiredChannelCrudController::class => self::SETTINGS,
        Crud\PaymentGatewayCrudController::class => self::SETTINGS,
        Crud\ZibalPaymentGatewayCrudController::class => self::SETTINGS,
        Crud\NowPaymentsPaymentGatewayCrudController::class => self::SETTINGS,
        Crud\CustomApiPaymentGatewayCrudController::class => self::SETTINGS,
        Crud\StorePaymentMethodCrudController::class => self::SETTINGS,
        Crud\VpnPanelCrudController::class => self::SETTINGS,
        Crud\VpnInboundCrudController::class => self::SETTINGS,
        UI\PanelSetupController::class => self::SETTINGS,
        UI\VpnPanelInboundActionsController::class => self::SETTINGS,
        Crud\PluginCrudController::class => self::SETTINGS,
        Crud\AdminUserCrudController::class => self::SETTINGS,
        Crud\AdminAuditLogCrudController::class => self::SETTINGS,
        Crud\ServiceNotificationLogCrudController::class => self::SETTINGS,
        Crud\BotMessageLogCrudController::class => self::SETTINGS,
    ];

    /** Section pages that exist in the modern layout only (404 in classic; the section tabs are modern only too). */
    public const MODERN_ONLY_ROUTES = ['admin_system_jobs'];

    /** Routes in another section than their controller's. */
    private const ROUTES = [
        // A service's own links: on VpnPanelInboundActionsController, but a service action (AdminCrudPermissions too).
        'admin_vpn_service_regenerate_config' => self::SERVICES,
    ];

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    /**
     * The section of a page: by route, else by its controller (EasyAdmin's CRUD controller, else the Symfony
     * controller); null for a page outside the admin.
     */
    public static function of(?string $route, ?string $controller): ?string
    {
        if (null !== $route && isset(self::ROUTES[$route])) {
            return self::ROUTES[$route];
        }
        if (null === $controller) {
            return null;
        }

        return self::CONTROLLERS[explode('::', $controller, 2)[0]] ?? null;
    }

    public static function ofRequest(Request $request): ?string
    {
        $crud = $request->attributes->get(EA::CRUD_CONTROLLER_FQCN);
        $controller = $request->attributes->get('_controller');

        return self::of(
            is_string($request->attributes->get('_route')) ? $request->attributes->get('_route') : null,
            is_string($crud) && '' !== $crud ? $crud : (is_string($controller) ? $controller : null),
        );
    }

    /** The section of the current page. */
    #[AsTwigFunction('admin_section')]
    public function current(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        return null === $request ? null : self::ofRequest($request);
    }

    /**
     * Every admin controller this map knows (the test checks it against src/Admin/UI).
     *
     * @return list<class-string>
     */
    public static function controllers(): array
    {
        return array_keys(self::CONTROLLERS);
    }

    /**
     * The pages of a section, whoever asks.
     *
     * @return list<Page>
     */
    public static function pages(string $section): array
    {
        $pages = [];
        foreach (self::PAGES[$section] ?? [] as $key => [$label, $controller, $route]) {
            $pages[] = ['key' => $key, 'label' => $label, 'controller' => $controller, 'route' => $route];
        }

        return $pages;
    }

    /** The permission a page needs: its route's, else its controller's (AdminCrudPermissions, as the server checks). */
    public static function permission(string $controller, ?string $route): string
    {
        return (null === $route ? null : AdminCrudPermissions::forRoute($route)?->value) ?? AdminCrudPermissions::view($controller);
    }

    /**
     * The first page of the section the current admin may open (where its sidebar item leads), or null.
     *
     * @return Page|null
     */
    public function landing(string $section): ?array
    {
        foreach (self::pages($section) as $page) {
            if ($this->security->isGranted(self::permission($page['controller'], $page['route']))) {
                return $page;
            }
        }

        return null;
    }

    /**
     * The section tabs above the current page: the pages of its section the admin may open (none for a section of one
     * page, or outside a section).
     *
     * @return list<Tab>
     */
    #[AsTwigFunction('admin_section_tabs')]
    public function tabs(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        $section = null === $request ? null : self::ofRequest($request);
        if (null === $section) {
            return [];
        }
        $crud = $request->attributes->get(EA::CRUD_CONTROLLER_FQCN);
        $controller = is_string($crud) && '' !== $crud ? $crud : explode('::', (string) $request->attributes->get('_controller'), 2)[0];

        $tabs = [];
        foreach (self::pages($section) as $page) {
            if ($this->security->isGranted(self::permission($page['controller'], $page['route']))) {
                $tabs[] = ['key' => $page['key'], 'label' => $page['label'], 'url' => $this->url($page), 'active' => $page['controller'] === $controller];
            }
        }

        return count($tabs) > 1 ? $tabs : [];
    }

    /**
     * @param Page $page
     */
    public function url(array $page): string
    {
        if (null !== $page['route']) {
            return $this->urlGenerator->generate($page['route']);
        }

        return $this->adminUrlGenerator->unsetAll()->setDashboard(UI\DashboardController::class)
            ->setController($page['controller'])->setAction(Action::INDEX)->generateUrl();
    }
}
