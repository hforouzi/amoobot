<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Security\AdminCrudPermissions;
use App\Entity\AdminUser;
use App\Entity\RequiredChannel;
use App\Entity\Setting;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentStatus;
use App\Security\AdminAuditLogger;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * Admin page consolidation: old URLs and route names redirect to the page that replaced them (and are gated like
 * it), the tabbed pages load and save, the payment tabs count right, the customers page searches, store payment
 * methods are managed from the gateways page, inbounds from their panel, and no admin route is left unmapped.
 */
final class AdminConsolidationTest extends IntegrationTestCase
{
    private const ROLE_PERMISSIONS = [
        AdminUser::ROLE_SUPPORT => ['ORDERS_VIEW', 'PAYMENTS_VIEW', 'CUSTOMERS_VIEW', 'SERVICES_VIEW', 'SERVICES_ASSIST'],
        AdminUser::ROLE_ADMIN => ['ORDERS_VIEW', 'ORDERS_MANAGE', 'PAYMENTS_VIEW', 'PAYMENTS_MODERATE', 'CUSTOMERS_VIEW', 'CUSTOMERS_MANAGE', 'SERVICES_VIEW', 'SERVICES_ASSIST', 'SERVICES_MANAGE', 'SERVICES_DELETE', 'PLANS_MANAGE', 'DISCOUNTS_MANAGE', 'BROADCAST_SEND', 'BOT_TEXTS_MANAGE', 'APPS_MANAGE'],
    ];

    /** Setting keys the settings tabs write; removed again so other tests see the defaults. */
    private const TOUCHED_SETTING_PREFIXES = ['renewal.', 'pricing.', 'traffic_addon.', 'sales.', 'automation.', 'orders.', 'consolidation.'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM admin_audit_log');
        $connection->executeStatement('DELETE FROM admin_user');
        $connection->executeStatement("DELETE FROM required_channel WHERE title LIKE 'Consolidation%'");
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement("DELETE FROM required_channel WHERE title LIKE 'Consolidation%'");
        foreach (self::TOUCHED_SETTING_PREFIXES as $prefix) {
            $connection->executeStatement('DELETE FROM setting WHERE key_name LIKE ?', [$prefix.'%']);
        }
        parent::tearDown();
    }

    /**
     * Every old list URL answers a redirect to its new page for a role allowed there (and the page opens), and is
     * closed (403) for a role that is not, the same as the new page.
     */
    public function testOldUrlsRedirectToTheirNewPageWithTheSameGating(): void
    {
        $user = $this->createUser('7100');
        $payment = $this->createPayment($this->createOrder($user, $this->createPlan()), null, PaymentStatus::NEEDS_REVIEW);
        self::getContainer()->get(AdminAuditLogger::class)->record('consolidation.test', 'Test', '1');
        $auditId = (int) $this->em->getConnection()->fetchOne("SELECT MAX(id) FROM admin_audit_log WHERE action = 'consolidation.test'");

        $old = [
            // old URL => [new path + query, permission]
            '/admin/payment-needs-review' => ['/admin/payment?tab=needs_review', 'PAYMENTS_VIEW'],
            '/admin/payment-provisioning-failed' => ['/admin/payment?tab=provisioning_failed', 'PAYMENTS_VIEW'],
            '/admin/payment-needs-review/'.$payment->getId() => ['/admin/payment/'.$payment->getId(), 'PAYMENTS_VIEW'],
            '/admin/user' => ['/admin/customer', 'CUSTOMERS_VIEW'],
            '/admin/user/'.$user->getId() => ['/admin/customer/'.$user->getId(), 'CUSTOMERS_VIEW'],
            '/admin/user?query=7100' => ['/admin/customer?query=7100', 'CUSTOMERS_VIEW'],
            '/admin/telegram-account' => ['/admin/customer', 'CUSTOMERS_VIEW'],
            '/admin/store-payment-method' => ['/admin/payment-gateway', 'GATEWAYS_MANAGE'],
            '/admin/settings/renewal-pricing' => ['/admin/settings?tab=sales', 'SETTINGS_MANAGE'],
            '/admin/settings/automation' => ['/admin/settings?tab=automation', 'SETTINGS_MANAGE'],
            '/admin/required-channel' => ['/admin/settings?tab=channels', 'SETTINGS_MANAGE'],
            '/admin/setting' => ['/admin/settings?tab=advanced', 'SETTINGS_MANAGE'],
            '/admin/bot-message-template' => ['/admin/bot-texts/messages', 'BOT_TEXTS_MANAGE'],
            '/admin/bot-button-label' => ['/admin/bot-texts/buttons', 'BOT_TEXTS_MANAGE'],
            '/admin/admin-audit-log' => ['/admin/logs/audit', 'LOGS_VIEW'],
            '/admin/admin-audit-log/'.$auditId => ['/admin/logs/audit/'.$auditId, 'LOGS_VIEW'],
            '/admin/service-notification-log' => ['/admin/logs/notifications', 'LOGS_VIEW'],
            '/admin/bot-message-log' => ['/admin/logs/bot-messages', 'LOGS_VIEW'],
        ];

        foreach ([AdminUser::ROLE_SUPER_ADMIN, AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT] as $role) {
            $this->client->loginUser($this->admin($role), 'main');
            foreach ($old as $url => [$target, $permission]) {
                $this->client->request('GET', $url);
                $response = $this->client->getResponse();
                if (!$this->allowed($role, $permission)) {
                    self::assertSame(403, $response->getStatusCode(), sprintf('%s GET %s', $role, $url));
                    $this->client->request('GET', $target);
                    self::assertSame(403, $this->client->getResponse()->getStatusCode(), sprintf('%s GET %s (new page)', $role, $target));
                    continue;
                }

                self::assertContains($response->getStatusCode(), [301, 302], sprintf('%s GET %s', $role, $url));
                $location = (string) $response->headers->get('Location');
                self::assertSame($target, $this->pathAndQuery($location), sprintf('%s GET %s', $role, $url));
                $this->client->request('GET', $location);
                self::assertSame(200, $this->client->getResponse()->getStatusCode(), sprintf('%s GET %s -> %s', $role, $url, $location));
            }
        }
    }

    public function testOldRouteNamesStillLeadToTheirPage(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $this->client->followRedirects();
        $router = self::getContainer()->get(RouterInterface::class);
        $names = [
            'admin_payment_needs_review_index' => [], 'admin_payment_provisioning_failed_index' => [],
            'admin_user_index' => [], 'admin_user_detail' => ['entityId' => $user->getId()], 'admin_telegram_account_index' => [],
            'admin_store_payment_method_index' => [], 'admin_renewal_pricing_settings' => [], 'admin_automation_settings' => [],
            'admin_required_channel_index' => [], 'admin_setting_index' => [], 'admin_bot_message_template_index' => [],
            'admin_bot_button_label_index' => [], 'admin_admin_audit_log_index' => [], 'admin_service_notification_log_index' => [],
            'admin_bot_message_log_index' => [], 'admin_vpn_inbound_index' => [],
        ];
        foreach ($names as $name => $parameters) {
            $this->client->request('GET', $router->generate($name, $parameters));
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $name);
        }
    }

    /**
     * A POST to an old settings URL (a form left open before the upgrade) reaches the new page with its body.
     */
    public function testAnOldFormPostIsRedirectedKeepingTheMethod(): void
    {
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $this->client->request('POST', '/admin/settings/automation', ['automation_batch_limit' => '5']);
        self::assertSame(307, $this->client->getResponse()->getStatusCode());
        self::assertSame('/admin/settings?tab=automation', $this->client->getResponse()->headers->get('Location'));
    }

    public function testPaymentTabsCountAndFilter(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan());
        $ids = [];
        foreach ([PaymentStatus::PENDING, PaymentStatus::PENDING, PaymentStatus::SUBMITTED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED, PaymentStatus::PROVISIONING_FAILED, PaymentStatus::CONFIRMED, PaymentStatus::REJECTED] as $status) {
            $ids[$status][] = $this->createPayment($order, null, $status)->getId();
        }
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPPORT), 'main');

        $crawler = $this->client->request('GET', '/admin/payment');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $expected = ['all' => 8, 'pending' => 3, 'needs_review' => 1, 'provisioning_failed' => 2, 'confirmed' => 1, 'rejected' => 1];
        foreach ($expected as $tab => $count) {
            $link = $crawler->filter(sprintf('.admin-tabs a[data-tab="%s"]', $tab));
            self::assertCount(1, $link, $tab.' tab');
            self::assertSame((string) $count, trim($link->filter('.admin-tab-count')->text()), $tab.' count');
            self::assertSame('all' === $tab ? '/admin/payment' : '/admin/payment?tab='.$tab, $this->pathAndQuery((string) $link->attr('href')));
        }
        self::assertSame('all', $crawler->filter('.admin-tabs a.active')->attr('data-tab'));

        $rows = [
            'pending' => [...$ids[PaymentStatus::PENDING], ...$ids[PaymentStatus::SUBMITTED]],
            'needs_review' => $ids[PaymentStatus::NEEDS_REVIEW],
            'provisioning_failed' => $ids[PaymentStatus::PROVISIONING_FAILED],
            'confirmed' => $ids[PaymentStatus::CONFIRMED],
            'rejected' => $ids[PaymentStatus::REJECTED],
        ];
        foreach ($rows as $tab => $expectedIds) {
            $crawler = $this->client->request('GET', '/admin/payment?tab='.$tab);
            self::assertSame($tab, $crawler->filter('.admin-tabs a.active')->attr('data-tab'));
            $listed = $crawler->filter('table.datagrid tbody tr[data-id]')->each(static fn (Crawler $row): int => (int) $row->attr('data-id'));
            sort($listed);
            sort($expectedIds);
            self::assertSame($expectedIds, $listed, $tab.' rows');
        }

        // The attention lists keep their oldest-first order.
        $crawler = $this->client->request('GET', '/admin/payment?tab=provisioning_failed');
        self::assertSame($ids[PaymentStatus::PROVISIONING_FAILED], $crawler->filter('table.datagrid tbody tr[data-id]')->each(static fn (Crawler $row): int => (int) $row->attr('data-id')));

        // An unknown tab is the full list.
        $crawler = $this->client->request('GET', '/admin/payment?tab=nope');
        self::assertCount(8, $crawler->filter('table.datagrid tbody tr[data-id]'));
    }

    public function testDashboardCardsLinkToTheFilteredPayments(): void
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan());
        $this->createPayment($order, null, PaymentStatus::NEEDS_REVIEW);
        $this->createPayment($order, null, PaymentStatus::PROVISIONING_FAILED);
        $this->createPayment($order, null, PaymentStatus::PROVISIONING_FAILED);

        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPPORT), 'main');
        $crawler = $this->client->request('GET', '/admin');
        foreach (['pending' => 0, 'needs_review' => 1, 'provisioning_failed' => 2] as $tab => $count) {
            $card = $crawler->filter(sprintf('a.admin-dashboard-card[data-tab="%s"]', $tab));
            self::assertCount(1, $card, $tab.' card');
            self::assertSame('/admin/payment?tab='.$tab, $this->pathAndQuery((string) $card->attr('href')));
            self::assertSame((string) $count, trim($card->filter('.admin-dashboard-card-count')->text()));
        }
    }

    public function testStorePaymentMethodsAreManagedOnTheGatewaysPage(): void
    {
        $gateway = $this->createGateway();
        $first = $this->storeMethod($gateway, 'Card A', 10);
        $second = $this->storeMethod($gateway, 'Card B', 20);
        $third = $this->storeMethod($gateway, 'Card C', 20);

        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $crawler = $this->client->request('GET', '/admin/payment-gateway');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['Card A', 'Card B', 'Card C'], $this->methodTitles($crawler));
        self::assertCount(1, $crawler->filter('#store-payment-methods a[href$="/admin/store-payment-method/new"]'));
        self::assertCount(1, $crawler->filter(sprintf('#store-payment-methods a[href$="/admin/store-payment-method/%d/edit"]', $first->getId())));

        // Move "Card C" up, then "Card A" down: C, A, B.
        $this->submitMethodForm($crawler, $third->getId(), 'up');
        self::assertTrue($this->client->getResponse()->isRedirect());
        $crawler = $this->client->request('GET', '/admin/payment-gateway');
        self::assertSame(['Card A', 'Card C', 'Card B'], $this->methodTitles($crawler));
        $this->submitMethodForm($crawler, $third->getId(), 'up');
        $crawler = $this->client->request('GET', '/admin/payment-gateway');
        self::assertSame(['Card C', 'Card A', 'Card B'], $this->methodTitles($crawler));
        // The first one has no "up", the last one no "down".
        self::assertCount(0, $crawler->filter(sprintf('#store-payment-methods form[data-method="%d"][data-action="up"]', $third->getId())));
        self::assertCount(0, $crawler->filter(sprintf('#store-payment-methods form[data-method="%d"][data-action="down"]', $second->getId())));

        // Disable / enable.
        $this->submitMethodForm($crawler, $second->getId(), 'toggle');
        $this->em->clear();
        self::assertFalse($this->em->find(StorePaymentMethod::class, $second->getId())?->isActive());
        $crawler = $this->client->request('GET', '/admin/payment-gateway');
        $this->submitMethodForm($crawler, $second->getId(), 'toggle');
        $this->em->clear();
        self::assertTrue($this->em->find(StorePaymentMethod::class, $second->getId())?->isActive());

        $actions = $this->em->getConnection()->fetchFirstColumn("SELECT action FROM admin_audit_log WHERE target_type = 'StorePaymentMethod' AND target_id = ? ORDER BY id", [(string) $second->getId()]);
        self::assertContains('store_payment_method.update', $actions);

        // A bad token changes nothing.
        $this->client->request('POST', sprintf('/admin/store-payment-method/%d/toggle', $second->getId()), ['_token' => 'bad']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        // Gated like the gateways page.
        foreach ([AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT] as $role) {
            $this->client->loginUser($this->admin($role), 'main');
            $this->client->request('POST', sprintf('/admin/store-payment-method/%d/move/up', $second->getId()));
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $role);
        }
    }

    public function testCustomersPageShowsAndSearchesCustomers(): void
    {
        $ali = $this->createUser('7001');
        $ali->setName('Ali Rezaei')->setMobile('09120000001');
        $ali->getTelegramAccount()?->setUsername('ali_r')->setLanguageCode('fa');
        $sara = $this->createUser('7002');
        $sara->setName('Sara Ahmadi')->setMobile('09350000002');
        $sara->getTelegramAccount()?->setUsername('sara99')->setLanguageCode('en');
        $this->em->flush();
        $this->createActiveService($ali);
        $this->createActiveService($ali);
        $this->createOrder($ali, $this->createPlan());

        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPPORT), 'main');
        $crawler = $this->client->request('GET', '/admin/customer');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $row = $crawler->filter(sprintf('tr[data-id="%d"]', $ali->getId()));
        self::assertCount(1, $row);
        $text = $row->text();
        foreach (['@ali_r', '7001', 'fa', 'Ali Rezaei', '09120000001'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertSame('2', trim($row->filter('[data-customer-count="services"]')->text()));
        self::assertSame('1', trim($row->filter('[data-customer-count="orders"]')->text()));
        $hrefs = implode(' ', $row->filter('a[href]')->each(static fn (Crawler $a): string => urldecode((string) $a->attr('href'))));
        self::assertStringContainsString('/admin/vpn-service?filters[user][comparison]==&filters[user][value]='.$ali->getId(), $hrefs);
        self::assertStringContainsString('/admin/order?filters[user][comparison]==&filters[user][value]='.$ali->getId(), $hrefs);
        self::assertStringContainsString('/admin/telegram-account/'.$ali->getTelegramAccount()?->getId(), $hrefs);
        self::assertStringContainsString('/admin/customer/'.$ali->getId(), $hrefs);

        // The links open for this role.
        foreach ($row->filter('a[href]')->each(static fn (Crawler $a): string => (string) $a->attr('href')) as $href) {
            $this->client->request('GET', $href);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'GET '.$href);
        }

        $searches = [
            '@sara99' => [$sara->getId()],
            'sara99' => [$sara->getId()],
            'Rezaei' => [$ali->getId()],
            '0935000' => [$sara->getId()],
            '7001' => [$ali->getId()],
            '#'.$sara->getId() => [$sara->getId()],
        ];
        foreach ($searches as $query => $expectedIds) {
            $crawler = $this->client->request('GET', '/admin/customer?query='.urlencode((string) $query));
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertSame($expectedIds, $crawler->filter('table.datagrid tbody tr[data-id]')->each(static fn (Crawler $r): int => (int) $r->attr('data-id')), 'search '.$query);
        }
        // A bare number also finds the customer with that id.
        $crawler = $this->client->request('GET', '/admin/customer?query='.$sara->getId());
        self::assertContains($sara->getId(), $crawler->filter('table.datagrid tbody tr[data-id]')->each(static fn (Crawler $r): int => (int) $r->attr('data-id')));
    }

    public function testSettingsTabsLoadAndSaveIndependently(): void
    {
        $channel = (new RequiredChannel())->setTitle('Consolidation channel')->setChatId('@consolidation');
        $this->em->persist($channel);
        $secret = (new Setting())->setKeyName('consolidation.secret_token')->setValue('do-not-render-me')->setType('text');
        $this->em->persist($secret);
        $this->em->flush();

        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        foreach (['sales', 'automation', 'wallet', 'resellers', 'plans', 'channels', 'advanced'] as $tab) {
            $crawler = $this->client->request('GET', '/admin/settings?tab='.$tab);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $tab);
            self::assertSame($tab, $crawler->filter('.admin-tabs a.active')->attr('data-tab'), $tab);
            self::assertCount(7, $crawler->filter('.admin-tabs a'));
        }
        $crawler = $this->client->request('GET', '/admin/settings');
        self::assertSame('sales', $crawler->filter('.admin-tabs a.active')->attr('data-tab'));

        // Sales & pricing: saves, and validates as before.
        $crawler = $this->client->request('GET', '/admin/settings?tab=sales');
        $this->client->submit($crawler->filter('form.admin-settings-form')->form(), ['pricing_global_discount_percent' => '12']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('12', $this->settingValue('pricing.global_discount_percent'));
        $crawler = $this->client->request('GET', '/admin/settings?tab=sales');
        $this->client->submit($crawler->filter('form.admin-settings-form')->form(), ['pricing_global_discount_percent' => '150']);
        self::assertSame('12', $this->settingValue('pricing.global_discount_percent'));
        self::assertStringContainsString('درصد تخفیف باید بین ۰ تا ۱۰۰ باشد.', (string) $this->client->getResponse()->getContent());
        // Saving one tab leaves the other tab's settings alone.
        self::assertNull($this->settingValue('automation.batch_limit'));

        // Automation.
        $crawler = $this->client->request('GET', '/admin/settings?tab=automation');
        $this->client->submit($crawler->filter('form.admin-settings-form')->form(), ['automation_batch_limit' => '37']);
        self::assertSame('37', $this->settingValue('automation.batch_limit'));
        self::assertSame('12', $this->settingValue('pricing.global_discount_percent'));
        $crawler = $this->client->request('GET', '/admin/settings?tab=automation');
        $this->client->submit($crawler->filter('form.admin-settings-form')->form(), ['automation_batch_limit' => '0']);
        self::assertSame('37', $this->settingValue('automation.batch_limit'));

        $pages = $this->em->getConnection()->fetchFirstColumn("SELECT JSON_UNQUOTE(JSON_EXTRACT(details, '$.page')) FROM admin_audit_log WHERE action = 'settings.update' ORDER BY id");
        self::assertSame(['renewal_pricing', 'automation'], $pages);

        // Required channels: listed, with the existing create/edit pages.
        $crawler = $this->client->request('GET', '/admin/settings?tab=channels');
        self::assertStringContainsString('Consolidation channel', $crawler->filter('#required-channels')->text());
        self::assertCount(1, $crawler->filter(sprintf('#required-channels a[href$="/admin/required-channel/%d/edit"]', $channel->getId())));
        self::assertCount(1, $crawler->filter('#required-channels a[href$="/admin/required-channel/new"]'));
        $this->client->request('GET', '/admin/required-channel/new');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Advanced: the raw keys, behind a warning, without their values.
        $crawler = $this->client->request('GET', '/admin/settings?tab=advanced');
        self::assertCount(1, $crawler->filter('.admin-advanced-warning'));
        self::assertCount(1, $crawler->filter('details#advanced-settings:not([open])'));
        self::assertStringContainsString('consolidation.secret_token', $crawler->filter('details#advanced-settings')->text());
        self::assertStringNotContainsString('do-not-render-me', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter(sprintf('details#advanced-settings a[href$="/admin/setting/%d/edit"]', $secret->getId())));

        // Gated: SETTINGS_MANAGE only.
        foreach ([AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT] as $role) {
            $this->client->loginUser($this->admin($role), 'main');
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, '/admin/settings?tab=automation', ['automation_batch_limit' => '99']);
                self::assertSame(403, $this->client->getResponse()->getStatusCode(), $role.' '.$method);
            }
        }
        self::assertSame('37', $this->settingValue('automation.batch_limit'));
    }

    public function testBotTextsAndLogsAreTabbedPages(): void
    {
        $pages = [
            '/admin/bot-texts/messages' => ['messages', 2],
            '/admin/bot-texts/buttons' => ['buttons', 2],
            '/admin/logs/audit' => ['audit', 3],
            '/admin/logs/notifications' => ['notifications', 3],
            '/admin/logs/bot-messages' => ['bot_messages', 3],
        ];
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        foreach ($pages as $url => [$active, $tabs]) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
            self::assertCount($tabs, $crawler->filter('.admin-tabs a'), $url);
            self::assertSame($active, $crawler->filter('.admin-tabs a.active')->attr('data-tab'), $url);
            foreach ($crawler->filter('.admin-tabs a')->each(static fn (Crawler $a): string => (string) $a->attr('href')) as $href) {
                self::assertArrayHasKey($this->pathAndQuery($href), $pages, $url.' links to '.$href);
            }
        }

        // Bot texts: plain admins too (BOT_TEXTS_MANAGE); logs are super admin only (LOGS_VIEW).
        $this->client->loginUser($this->admin(AdminUser::ROLE_ADMIN), 'main');
        foreach (array_keys($pages) as $url) {
            $this->client->request('GET', $url);
            self::assertSame(str_starts_with($url, '/admin/bot-texts') ? 200 : 403, $this->client->getResponse()->getStatusCode(), $url);
        }
    }

    public function testInboundsAreReachedFromTheirPanel(): void
    {
        $inbound = $this->createTestInbound();
        $other = $this->createTestInbound();
        $other->setTitle('Other panel inbound');
        $this->em->flush();
        $panelId = $inbound->getPanel()->getId();

        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        foreach (['/admin/vpn-panel/'.$panelId, '/admin/panel-setup/'.$panelId] as $page) {
            $crawler = $this->client->request('GET', $page);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $page);
            $section = $crawler->filter('#panel-inbounds');
            self::assertCount(1, $section, $page);
            self::assertStringContainsString('Test inbound', $section->text());
            self::assertStringNotContainsString('Other panel inbound', $section->text());
            self::assertCount(1, $section->filter(sprintf('a[href$="/admin/vpn-inbound/%d"]', $inbound->getId())), $page);
            self::assertCount(1, $section->filter(sprintf('a[href$="/admin/vpn-panel/%d/inbounds"]', $panelId)), $page);
        }

        // The panel's inbound list (the existing filtered list) still opens.
        $this->client->followRedirects();
        $crawler = $this->client->request('GET', '/admin/vpn-panel/'.$panelId.'/inbounds');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Test inbound', $crawler->filter('table.datagrid')->text());
        self::assertStringNotContainsString('Other panel inbound', $crawler->filter('table.datagrid')->text());
    }

    /**
     * Fail-closed map: every admin route resolves to a permission (or is one of the open pages). A new route that
     * nobody mapped would be closed to everyone; this makes that visible here instead of in production.
     */
    public function testNoAdminRouteIsUnmapped(): void
    {
        $unmapped = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/admin')) {
                continue;
            }
            $crud = $route->getDefault('crudControllerFqcn');
            if (is_string($crud)) {
                if (null === AdminCrudPermissions::forRoute($name) && null === AdminCrudPermissions::forController($crud)) {
                    $unmapped[] = $name.' ('.$crud.')';
                }
                continue;
            }
            $controller = (string) $route->getDefault('_controller');
            $class = explode('::', $controller)[0];
            if (null === AdminCrudPermissions::forRoute($name) && null === AdminCrudPermissions::forController($class) && !AdminCrudPermissions::isOpen($class)) {
                $unmapped[] = $name.' ('.$controller.')';
            }
        }
        self::assertSame([], $unmapped, 'admin routes without a permission');
    }

    private function storeMethod(\App\Entity\PaymentGateway $gateway, string $title, int $sortOrder): StorePaymentMethod
    {
        $method = (new StorePaymentMethod())->setGateway($gateway)->setTitle($title)->setSortOrder($sortOrder)->setIsActive(true)->setCurrency('IRR');
        $this->em->persist($method);
        $this->em->flush();

        return $method;
    }

    /**
     * @return list<string>
     */
    private function methodTitles(Crawler $crawler): array
    {
        return $crawler->filter('#store-payment-methods tbody tr[data-id] .admin-store-method-title')->each(static fn (Crawler $c): string => trim($c->text()));
    }

    private function submitMethodForm(Crawler $crawler, ?int $id, string $action): void
    {
        $form = $crawler->filter(sprintf('#store-payment-methods form[data-method="%d"][data-action="%s"]', $id, $action));
        self::assertCount(1, $form, $action.' form for '.$id);
        $this->client->submit($form->form());
    }

    /** @phpstan-impure */
    private function settingValue(string $key): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT value FROM setting WHERE key_name = ?', [$key]);

        return false === $value ? null : (string) $value;
    }

    private function pathAndQuery(string $url): string
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? '');

        return isset($parts['query']) ? $path.'?'.$parts['query'] : $path;
    }

    private function allowed(string $role, string $permission): bool
    {
        return AdminUser::ROLE_SUPER_ADMIN === $role || in_array($permission, self::ROLE_PERMISSIONS[$role], true);
    }

    private function admin(string $role): AdminUser
    {
        $email = strtolower(str_replace('ROLE_', '', $role)).'@consolidation.test';

        return $this->em->getRepository(AdminUser::class)->findOneBy(['email' => $email]) ?? $this->createAdmin($email, [$role]);
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
