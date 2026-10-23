<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\DiscountCode;
use App\Entity\VpnPanel;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\QueryCounter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Admin presentation: the dashboard per role (and its query budget), the list columns, and that no secret (panel
 * path, token, password, gateway key, subscription path) reaches a list page.
 */
final class AdminUiPolishTest extends IntegrationTestCase
{
    private const PANEL_SECRET_PATH = 'Xk9sEcReTpAtH42';
    private const PANEL_TOKEN = 'tok_SUPERSECRET_modern_123';
    private const PANEL_PASSWORD = 'P@ssw0rd-legacy-SECRET';
    private const LEGACY_PATH = 'secretPanelPath77';
    private const SUB_PREFIX = 'sub-secret-prefix';
    private const GATEWAY_KEY = 'np_api_key_SECRET_42';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM admin_user');
        $connection->executeStatement('DELETE FROM admin_audit_log');
        $connection->executeStatement("DELETE FROM discount_code WHERE code LIKE 'POLISH%'");
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM discount_code WHERE code LIKE 'POLISH%'");
        parent::tearDown();
    }

    public function testDashboardShowsEachRoleOnlyWhatItCanUse(): void
    {
        $this->seed();
        $expected = [
            // role => [sections, today cards, shortcuts]
            AdminUser::ROLE_SUPER_ADMIN => [['attention', 'today', 'services', 'wallets', 'panel'], ['orders', 'payments', 'revenue', 'customers'], ['add_plan', 'broadcast', 'bot_logs']],
            AdminUser::ROLE_ADMIN => [['attention', 'today', 'services', 'wallets'], ['orders', 'payments', 'revenue', 'customers'], ['add_plan', 'broadcast']],
            AdminUser::ROLE_SUPPORT => [['attention', 'today', 'services', 'wallets'], ['orders', 'payments', 'revenue', 'customers'], []],
        ];
        foreach ($expected as $role => [$sections, $today, $shortcuts]) {
            $this->client->loginUser($this->admin($role), 'main');
            $crawler = $this->client->request('GET', '/admin');
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $role);

            $shown = array_values(array_unique($crawler->filter('[data-card]')->each(static fn (Crawler $c): string => (string) $c->attr('data-card'))));
            self::assertSame($sections, $shown, $role.' sections');
            self::assertSame($today, $crawler->filter('[data-card="today"]')->each(static fn (Crawler $c): string => (string) $c->attr('data-tab')), $role.' today');
            self::assertSame($shortcuts, $crawler->filter('[data-shortcut]')->each(static fn (Crawler $c): string => (string) $c->attr('data-shortcut')), $role.' shortcuts');

            // Every card links to a page the role can open.
            foreach ($crawler->filter('a[data-card], [data-card] a, a[data-shortcut]')->each(static fn (Crawler $a): string => (string) $a->attr('href')) as $href) {
                $this->client->request('GET', $href);
                self::assertSame(200, $this->client->getResponse()->getStatusCode(), $role.' GET '.$href);
            }
        }
    }

    public function testDashboardNumbers(): void
    {
        $this->seed();
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $crawler = $this->client->request('GET', '/admin');
        $count = static fn (string $card, string $tab): string => trim($crawler->filter(sprintf('[data-card="%s"][data-tab="%s"] .admin-dashboard-card-count', $card, $tab))->text());

        self::assertSame('1', $count('attention', 'needs_review'));
        self::assertSame('2', $count('attention', 'provisioning_failed'));
        self::assertSame('1', $count('attention', 'pending'));
        self::assertSame('/admin/payment?tab=needs_review', $crawler->filter('[data-card="attention"][data-tab="needs_review"]')->attr('href'));

        self::assertSame('4', $count('today', 'orders'));
        self::assertSame('1', $count('today', 'payments'));
        // The confirmed payment's amount, in the store currency, with thousands separators.
        self::assertStringContainsString('1,500,000', $count('today', 'revenue'));
        self::assertSame('2', $count('today', 'customers'));

        self::assertSame('2', $count('services', 'active'));
        self::assertSame('1', $count('services', 'expiring'));
        self::assertSame('1', $count('services', 'expired'));
        self::assertSame('1', $count('services', 'suspended'));
        self::assertSame('/admin/vpn-service?tab=expiring', $crawler->filter('[data-card="services"][data-tab="expiring"]')->attr('href'));

        // Germany (connected), Netherlands (degraded) and the inbound helper's "Test panel" (never tested).
        $panels = $crawler->filter('[data-card="panel"]');
        self::assertCount(3, $panels);
        self::assertStringContainsString('/admin/vpn-panel/', (string) $panels->filter('a')->attr('href'));
        self::assertCount(1, $crawler->filter('[data-card="panel"] .badge-success'));
        self::assertCount(1, $crawler->filter('[data-card="panel"] .badge-warning'), 'the degraded panel');
        self::assertCount(1, $crawler->filter('[data-card="panel"] .badge-secondary'), 'the untested panel');

        // The services tab the card links to lists exactly those services.
        $crawler = $this->client->request('GET', '/admin/vpn-service?tab=expiring');
        self::assertCount(1, $crawler->filter('table.datagrid tbody tr[data-id]'));
    }

    public function testDashboardEmptyStatesAreIntentional(): void
    {
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $crawler = $this->client->request('GET', '/admin');
        self::assertCount(1, $crawler->filter('.admin-dashboard-all-clear'));
        self::assertCount(0, $crawler->filter('[data-card="attention"]'));
        self::assertCount(1, $crawler->filter('.admin-dashboard-empty a[href$="/admin/panel-setup/new"]'));
    }

    /**
     * The dashboard costs a fixed number of queries, however many payments, services and panels there are.
     */
    public function testDashboardStaysWithinAFixedQueryBudget(): void
    {
        $admin = $this->admin(AdminUser::ROLE_SUPER_ADMIN);
        $this->client->loginUser($admin, 'main');
        $small = $this->dashboardQueries();

        $this->seed();
        for ($i = 0; $i < 15; ++$i) {
            $this->createTestInbound();
            $this->createActiveService($this->createUser());
            $this->createPayment($this->createOrder($this->createUser(), $this->createPlan()), null, PaymentStatus::NEEDS_REVIEW);
        }
        $large = $this->dashboardQueries();

        self::assertSame($small, $large, 'no query per row');
        self::assertLessThanOrEqual(10, $large, 'dashboard query budget (7 today: 6 aggregates + the admin user)');
    }

    /**
     * Each list shows its short set of columns (the rest is on the detail page) and answers 200.
     */
    public function testListsShowTheirEssentialColumns(): void
    {
        $this->seed();
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $lists = [
            '/admin/vpn-service' => ['id', 'user', 'plan', 'panel', 'status', 'expiresAt', 'traffic', 'lastUsageSyncedAt'],
            '/admin/vpn-panel' => ['id', 'title', 'health', 'isActive', 'probedAt', 'host'],
            '/admin/order' => ['id', 'user', 'plan', 'type', 'amount', 'status', 'createdAt'],
            '/admin/payment' => ['id', 'customer', 'order', 'paidWith', 'amount', 'status', 'createdAt'],
            '/admin/customer' => ['id', 'name', 'telegram', 'servicesCount', 'ordersCount', 'status', 'createdAt'],
            '/admin/plan' => ['id', 'title', 'durationDays', 'trafficGb', 'price', 'inbound', 'isActive'],
            '/admin/discount-code' => ['id', 'code', 'value', 'uses', 'endsAt', 'isActive'],
        ];
        foreach ($lists as $url => $columns) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
            self::assertSame($columns, $crawler->filter('table.datagrid thead th[data-column]')->each(static fn (Crawler $th): string => (string) $th->attr('data-column')), $url);
            self::assertCount(1, $crawler->filter('.admin-table-scroll table.datagrid'), $url.' scrolls in its own container');
            self::assertStringNotContainsString('badge-secondary">'.'null', (string) $this->client->getResponse()->getContent());
        }

        // Formats: status badges, amounts with separators, days left, traffic bar, host only.
        $html = (string) $this->client->request('GET', '/admin/vpn-service')->html();
        self::assertStringContainsString('class="badge badge-success admin-status" data-status="active"', $html);
        self::assertStringContainsString('admin-progress-bar', $html);
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}<\/bdi> <small/', $html);
        $html = (string) $this->client->request('GET', '/admin/order')->html();
        self::assertStringContainsString('1,500,000', $html);
        $html = (string) $this->client->request('GET', '/admin/vpn-panel')->html();
        self::assertStringContainsString('de1.example-vpn.net:2053', $html);

        // Remote id and username moved to the detail page, with a copy button.
        $serviceId = (int) $this->em->getConnection()->fetchOne('SELECT MIN(id) FROM vpn_service');
        $crawler = $this->client->request('GET', '/admin/vpn-service/'.$serviceId);
        self::assertGreaterThanOrEqual(2, $crawler->filter('.admin-copyable button[data-copy]')->count());
    }

    public function testNoSecretReachesAListPage(): void
    {
        $this->seed();
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        foreach (['/admin/vpn-panel', '/admin/payment-gateway', '/admin/vpn-service', '/admin', '/admin/vpn-inbound'] as $url) {
            $this->client->request('GET', $url);
            $html = (string) $this->client->getResponse()->getContent();
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
            foreach ([self::PANEL_SECRET_PATH, self::PANEL_TOKEN, self::PANEL_PASSWORD, self::LEGACY_PATH, self::SUB_PREFIX, self::GATEWAY_KEY] as $secret) {
                self::assertStringNotContainsString($secret, $html, $url.' shows '.$secret);
            }
        }
        // The legacy columns are not in the panel list.
        $crawler = $this->client->request('GET', '/admin/vpn-panel');
        foreach (['apiVersion', 'authMode', 'clientApiMode', 'basePath', 'username', 'publicHost', 'subscriptionPathPrefix', 'baseUrl'] as $legacy) {
            self::assertCount(0, $crawler->filter(sprintf('th[data-column="%s"]', $legacy)), $legacy);
        }
    }

    public function testThePanelListActiveSwitchWorksAndIsAudited(): void
    {
        $this->seed();
        $panel = $this->em->getRepository(VpnPanel::class)->findOneBy(['title' => 'Germany']);
        self::assertInstanceOf(VpnPanel::class, $panel);
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');

        $crawler = $this->client->request('GET', '/admin/vpn-panel');
        $switch = $crawler->filter(sprintf('tr[data-id="%d"] td[data-column="isActive"] input', $panel->getId()));
        self::assertCount(1, $switch);
        $url = (string) $switch->attr('data-toggle-url');
        self::assertNotSame('', $url);

        $this->client->xmlHttpRequest('PATCH', $url.(str_contains($url, '?') ? '&' : '?').'newValue=false');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertFalse($this->em->find(VpnPanel::class, $panel->getId())?->isActive());
        self::assertSame('vpn_panel.update', $this->em->getConnection()->fetchOne("SELECT action FROM admin_audit_log WHERE target_type = 'VpnPanel' ORDER BY id DESC LIMIT 1"));

        // Plain admins cannot (panels are super admin only).
        $this->client->loginUser($this->admin(AdminUser::ROLE_ADMIN), 'main');
        $this->client->xmlHttpRequest('PATCH', $url.(str_contains($url, '?') ? '&' : '?').'newValue=true');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testTheUserMenuShowsTheWholeNameOrEmail(): void
    {
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $crawler = $this->client->request('GET', '/admin');
        self::assertSame('super_admin@polish.test', trim($crawler->filter('.navbar-custom-menu .user-name')->text()));
        // The locale switcher is beside the menu, not inside the name's box.
        self::assertCount(1, $crawler->filter('.admin-topbar-tools .admin-locale-switcher'));
    }

    private function dashboardQueries(): int
    {
        $counter = self::getContainer()->get(QueryCounter::class);
        $this->em->clear();
        $counter->start();
        $this->client->request('GET', '/admin');
        $queries = $counter->stop();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return $queries;
    }

    /**
     * Two panels (one connected with a secret path and token, one legacy and degraded with credentials), services in
     * every state, today's orders and payments, a gateway with a secret key, a discount code.
     */
    private function seed(): void
    {
        $now = new \DateTimeImmutable();
        $modern = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Germany')->setBaseUrl('https://de1.example-vpn.net:2053/'.self::PANEL_SECRET_PATH)
            ->setIntegrationMode(SanaeiIntegrationMode::MODERN)->setPlainApiToken(self::PANEL_TOKEN)->setIsActive(true)
            ->setSubscriptionPathPrefix('/'.self::SUB_PREFIX.'/');
        $modern->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', $now->modify('-10 minutes'));
        $legacy = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Netherlands')->setBaseUrl('http://nl.example-vpn.net:54321/'.self::LEGACY_PATH)
            ->setIntegrationMode(SanaeiIntegrationMode::LEGACY)->setUsername('admin_nl')->setPlainPassword(self::PANEL_PASSWORD)->setBasePath('/'.self::LEGACY_PATH)->setIsActive(true);
        $legacy->recordProbe(SanaeiIntegrationMode::LEGACY, 'ok', $now->modify('-1 hour'));
        foreach ([1, 2, 3] as $_) {
            $legacy->recordCallFailure($now);
        }
        $this->em->persist($modern);
        $this->em->persist($legacy);
        $this->em->flush();

        $inbound = $this->createTestInbound();
        $inbound->setPanel($modern);
        $plan = $this->createPlan(1_500_000, $inbound);
        $gateway = $this->createGateway(PaymentGatewayType::NOWPAYMENTS, ['api_key' => self::GATEWAY_KEY, 'ipn_secret' => 'ipn_SECRET']);

        $customerA = $this->createUser('8800001');
        $customerB = $this->createUser('8800002');
        $statuses = [PaymentStatus::CONFIRMED, PaymentStatus::NEEDS_REVIEW, PaymentStatus::PROVISIONING_FAILED, PaymentStatus::PROVISIONING_FAILED];
        foreach ($statuses as $i => $status) {
            $payment = $this->createPayment($this->createOrder(0 === $i % 2 ? $customerA : $customerB, $plan), $gateway, $status);
            if (PaymentStatus::CONFIRMED === $status) {
                $payment->setConfirmedAt($now);
            }
        }
        // A payment waiting for approval, on an order of two days ago (not one of today's orders).
        $this->createPayment($this->createOrder($customerA, $plan), $gateway, PaymentStatus::SUBMITTED);
        $this->em->getConnection()->executeStatement('UPDATE `order` SET created_at = ? WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM `order`) o)', [$now->modify('-2 days')->format('Y-m-d H:i:s')]);

        $services = [
            [VpnServiceStatus::ACTIVE, '+20 days'], [VpnServiceStatus::ACTIVE, '+1 day'], [VpnServiceStatus::EXPIRED, '-2 days'], [VpnServiceStatus::SUSPENDED, '+5 days'],
        ];
        foreach ($services as [$status, $expires]) {
            $service = $this->createActiveService($customerA, $inbound)->setStatus($status)->setExpiresAt($now->modify($expires))
                ->setSubscriptionUrl('https://de1.example-vpn.net:2096/'.self::SUB_PREFIX.'/abc')->setLastUsageSyncedAt($now->modify('-5 minutes'));
            $service->setTrafficUsedBytes(12 * 1073741824);
        }
        $this->em->persist((new DiscountCode())->setCode('POLISH10')->setType(DiscountCode::TYPE_PERCENT)->setValue(10)->setMaxUses(100));
        $this->em->flush();
    }

    private function admin(string $role): AdminUser
    {
        $email = strtolower(str_replace('ROLE_', '', $role)).'@polish.test';
        $admin = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => $email]);
        if (null === $admin) {
            $admin = (new AdminUser())->setEmail($email)->setPassword('unused')->setRoles([$role])->setIsActive(true);
            $this->em->persist($admin);
            $this->em->flush();
        }

        return $admin;
    }
}
