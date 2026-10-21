<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\UI\Crud\AdminAuditLogCrudController;
use App\Admin\UI\Crud\AdminUserCrudController;
use App\Admin\UI\Crud\AppDownloadCrudController;
use App\Admin\UI\Crud\BotMessageTemplateCrudController;
use App\Admin\UI\Crud\BroadcastDeliveryCrudController;
use App\Admin\UI\Crud\BroadcastMessageCrudController;
use App\Admin\UI\Crud\DiscountCodeCrudController;
use App\Admin\UI\Crud\DiscountUsageCrudController;
use App\Admin\UI\Crud\OrderCrudController;
use App\Admin\UI\Crud\OrderDraftCrudController;
use App\Admin\UI\Crud\PaymentCrudController;
use App\Admin\UI\Crud\PaymentGatewayCrudController;
use App\Admin\UI\Crud\PlanCrudController;
use App\Admin\UI\Crud\PluginCrudController;
use App\Admin\UI\Crud\TrialClaimCrudController;
use App\Admin\UI\Crud\TrialPlanCrudController;
use App\Admin\UI\Crud\UserCrudController;
use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Admin\UI\Crud\VpnServiceCrudController;
use App\Entity\AdminUser;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The admin menu: its groups and entries per role, every entry a role sees opens for that role (the menu matches the
 * server-side gating), and the pages taken out of the menu are linked from their parent page.
 */
final class MenuStructureTest extends IntegrationTestCase
{
    private KernelBrowser $client;
    private AdminUser $admin;
    private AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->admin = $this->createAdmin('menu-admin@shop.test', [AdminUser::ROLE_ADMIN]);
        $this->superAdmin = $this->createAdmin('menu-super@shop.test', [AdminUser::ROLE_SUPER_ADMIN]);

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    public function testPlainAdminMenu(): void
    {
        self::assertSame($this->commonGroups(), $this->menuFor($this->admin));
    }

    /**
     * Support: read-only orders, payments, customers and services; no discount codes, catalog, messaging or system.
     */
    public function testSupportMenu(): void
    {
        $support = $this->createAdmin('menu-support@shop.test', [AdminUser::ROLE_SUPPORT]);

        self::assertSame([
            '' => ['/admin', '/admin/logout'],
            $this->trans('admin.menu.sales') => [
                $this->path(OrderCrudController::class),
                $this->path(PaymentCrudController::class),
            ],
            $this->trans('admin.menu.customers') => [
                $this->path(UserCrudController::class),
                $this->path(VpnServiceCrudController::class),
            ],
        ], $this->menuFor($support));

        // Every entry Support sees opens for Support.
        foreach ($this->menuFor($support) as $entries) {
            foreach ($entries as $entry) {
                $target = (string) preg_replace('/^.* > /', '', $entry);
                if ('/admin/logout' !== $target) {
                    $this->client->request('GET', $target);
                    self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'support GET '.$target);
                }
            }
        }
    }

    public function testSuperAdminMenu(): void
    {
        self::assertSame($this->commonGroups() + [
            $this->trans('admin.menu.system') => [
                $this->path(PaymentGatewayCrudController::class),
                $this->path(VpnPanelCrudController::class),
                '/admin/settings',
                $this->path(PluginCrudController::class),
                $this->path(AdminUserCrudController::class),
                $this->path(AdminAuditLogCrudController::class),
            ],
        ], $this->menuFor($this->superAdmin));
    }

    /**
     * The consolidated pages are one entry each, named after the page (not one of its tabs).
     */
    public function testConsolidatedEntriesAreNamedAfterTheirPage(): void
    {
        $this->client->loginUser($this->superAdmin, 'main');
        $crawler = $this->client->request('GET', '/admin');
        $labels = $crawler->filter('#main-menu a.ea-sidebar-item-link')->each(static fn (Crawler $a): string => trim($a->text()));
        foreach (['admin.customers', 'admin.bot_texts', 'admin.settings', 'admin.logs', 'admin.payment_gateways', 'admin.vpn_panels', 'admin.apps'] as $key) {
            self::assertContains($this->trans($key), $labels, $key);
        }
        foreach (['admin.users', 'admin.telegram_accounts', 'admin.bot_button_labels', 'admin.store_payment_methods', 'admin.vpn_inbounds', 'admin.required_channels', 'admin.renewal_pricing_settings', 'admin.automation_settings', 'admin.payments_needs_review', 'admin.payments_provisioning_failed', 'admin.notifications', 'admin.bot_logs', 'admin.client_apps', 'admin.app_downloads'] as $key) {
            self::assertNotContains($this->trans($key), $labels, $key);
        }
    }

    public function testEveryMenuEntryOpensForTheRoleThatSeesIt(): void
    {
        foreach ([$this->admin, $this->superAdmin] as $user) {
            $menu = $this->menuFor($user);
            foreach ($menu as $entries) {
                foreach ($entries as $entry) {
                    $target = (string) preg_replace('/^.* > /', '', $entry);
                    if ('/admin/logout' === $target) {
                        continue;
                    }
                    $url = str_starts_with($target, 'route:') ? $this->router()->generate(substr($target, 6)) : $target;
                    $this->client->request('GET', $url);
                    self::assertSame(200, $this->client->getResponse()->getStatusCode(), sprintf('%s: GET %s', $user->getEmail(), $url));
                }
            }
        }
    }

    public function testPagesOutOfTheMenuAreLinkedFromTheirParentPage(): void
    {
        $this->client->loginUser($this->admin, 'main');
        $menu = implode(' ', array_merge(...array_values($this->menuFor($this->admin))));
        $links = [
            PlanCrudController::class => [$this->path(TrialPlanCrudController::class), $this->path(TrialClaimCrudController::class), 'routeName=admin_bulk_plan_price_adjustment'],
            OrderCrudController::class => [$this->path(OrderDraftCrudController::class)],
            DiscountCodeCrudController::class => [$this->path(DiscountUsageCrudController::class)],
        ];
        foreach ($links as $parent => $children) {
            $crawler = $this->client->request('GET', $this->path($parent));
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            $hrefs = implode(' ', $crawler->filter('a[href]')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
            foreach ($children as $child) {
                self::assertStringContainsString($child, $hrefs, sprintf('%s links to %s', $parent, $child));
                self::assertStringNotContainsString(str_starts_with($child, '/') ? $child.' ' : $child, $menu.' ', $child.' is not in the menu');
                $this->client->request('GET', str_starts_with($child, '/') ? $child : '/admin/plans/bulk-adjust-prices');
                self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'GET '.$child);
            }
        }
        // Broadcast deliveries: reached from a broadcast (BroadcastMessageCrudController::viewDeliveries), not the menu.
        self::assertStringNotContainsString($this->path(BroadcastDeliveryCrudController::class).' ', $menu.' ');
    }

    /**
     * @return array<string, list<string>>
     */
    private function commonGroups(): array
    {
        return [
            // Dashboard (first group) and logout (last group) have no label.
            '' => ['/admin', '/admin/logout'],
            $this->trans('admin.menu.sales') => [
                $this->path(OrderCrudController::class),
                $this->path(PaymentCrudController::class),
                $this->path(DiscountCodeCrudController::class),
            ],
            $this->trans('admin.menu.catalog') => [
                $this->path(PlanCrudController::class),
                // Downloads and client apps: one "Apps" page with two tabs.
                $this->path(AppDownloadCrudController::class),
            ],
            $this->trans('admin.menu.customers') => [
                $this->path(UserCrudController::class),
                $this->path(VpnServiceCrudController::class),
            ],
            $this->trans('admin.menu.messaging') => [
                $this->path(BroadcastMessageCrudController::class),
                $this->path(BotMessageTemplateCrudController::class),
            ],
        ];
    }

    /**
     * The sidebar as group label => entries in order; an entry is a path, "route:<name>" for a custom route, and
     * "<submenu title> > <entry>" inside a submenu. Unlabelled groups (dashboard, logout) share the "" key in order.
     *
     * @return array<string, list<string>>
     */
    private function menuFor(AdminUser $user): array
    {
        $this->client->loginUser($user, 'main');
        $crawler = $this->client->request('GET', '/admin');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $menu = [];
        $crawler->filter('#main-menu .ea-sidebar-group')->each(function (Crawler $group) use (&$menu): void {
            $label = $group->filter('.ea-sidebar-group-label-text');
            $key = $label->count() > 0 ? trim($label->text()) : '';
            $entries = $group->filter('a.ea-sidebar-item-link')->each(function (Crawler $link): ?string {
                $href = (string) $link->attr('href');
                if ('' === $href || '#' === $href) {
                    return null;
                }
                parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
                $entry = isset($query['routeName']) ? 'route:'.$query['routeName'] : (string) parse_url($href, PHP_URL_PATH);
                $submenu = $link->closest('.ea-sidebar-submenu');
                if (null !== $submenu) {
                    $entry = trim($submenu->filter('.ea-sidebar-submenu-title')->text()).' > '.$entry;
                }

                return $entry;
            });
            $menu[$key] = array_merge($menu[$key] ?? [], array_values(array_filter($entries, static fn (?string $e): bool => null !== $e)));
        });

        return $menu;
    }

    private function path(string $controller): string
    {
        foreach ($this->router()->getRouteCollection() as $route) {
            if ($controller === $route->getDefault('crudControllerFqcn') && 'index' === $route->getDefault('crudAction')) {
                return $route->getPath();
            }
        }
        self::fail('No EasyAdmin index route for '.$controller);
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get(RouterInterface::class);
    }

    private function trans(string $key): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, [], 'admin', 'fa');
    }

    /**
     * @param list<string> $roles
     */
    private function createAdmin(string $email, array $roles): AdminUser
    {
        $admin = (new AdminUser())->setEmail($email)->setPassword('unused')->setRoles($roles)->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }
}
