<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminUser;
use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\Plugin;
use App\Entity\User;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Security\AdminPermission;
use App\Security\AdminRolePermissions;
use App\Tests\Support\AdminForms;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Admin safety (docs/design/admin-panel-audit.md 2.6, PM12): a GET never acts, a POST needs its token, each action needs
 * its permission; the web delete of a service goes to the panel first; the raw service form writes nothing; the test
 * client is removed again.
 */
final class AdminSafetyTest extends IntegrationTestCase
{
    use AdminForms;

    private KernelBrowser $client;
    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    private VpnInbound $inbound;
    private VpnService $service;
    private User $owner;
    /** A customer's client on the same inbound: the test client's cleanup must never touch it. */
    private string $otherClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM plugin');
        $this->em->getConnection()->executeStatement('DELETE FROM broadcast_delivery');
        $this->em->getConnection()->executeStatement('DELETE FROM broadcast_message');

        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Main panel')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->setUsername(Fake3xuiPanel::USERNAME)->setPlainPassword(Fake3xuiPanel::PASSWORD)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->inbound = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->em->persist($this->panel);
        $this->em->persist($this->inbound);
        $this->em->flush();

        $this->owner = $this->createUser();
        $this->service = $this->serviceOnPanel('customer_1');
        $this->otherClient = 'customer_2';
        $this->serviceOnPanel($this->otherClient);
        $this->fake->requests = [];

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        foreach (['sync_panel', 'resync', 'sync_metadata', 'test_client', 'regenerate_inbound', 'regenerate_service', 'plugin_enable', 'plugin_disable', 'payment_confirm', 'payment_retry', 'payment_reject', 'payment_reject_refunded', 'broadcast_retry', 'service_delete'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('actions')]
    public function testAGetOnlyShowsTheActionAndAPostNeedsItsToken(string $action): void
    {
        [$url, $permission, $fields, $shown, $done] = $this->action($action);
        $before = $this->state();

        $page = $this->client->request('GET', $url);
        $this->assertAnswered(200, 'GET '.$url);
        foreach ($shown as $text) {
            self::assertStringContainsString($text, $page->filter('[data-action-confirm]')->text(), 'the page says what will happen');
        }
        self::assertSame($before, $this->state(), 'a GET changes nothing and calls nothing');

        $this->client->request('POST', $url, $fields);
        self::assertTrue($this->client->getResponse()->isRedirect(), 'no token');
        self::assertSame($before, $this->state(), 'no token: nothing changed');

        $this->client->request('POST', $url, ['_token' => 'forged'] + $fields);
        self::assertTrue($this->client->getResponse()->isRedirect(), 'wrong token');
        self::assertSame($before, $this->state(), 'wrong token: nothing changed');

        $form = $this->client->request('GET', $url)->filter('[data-action-confirm] form')->form();
        $this->client->request('POST', $url, $fields + $form->getPhpValues());
        self::assertTrue($this->client->getResponse()->isRedirect(), 'valid token');
        self::assertNotSame($before, $this->state(), 'the action ran');
        $done();

        $this->assertForbiddenWithout($permission, $url);
    }

    public function testTheConfirmButtonPostsToTheSameUrl(): void
    {
        $payment = $this->payment(PaymentStatus::SUBMITTED);
        $this->client->request('GET', '/admin/payment?tab=needs_review');
        $list = 'http://localhost/admin/payment?tab=submitted';
        $this->client->request('GET', '/admin/payment/'.$payment->getId().'/reject?refunded=1', [], [], ['HTTP_REFERER' => $list]);
        $crawler = $this->client->getCrawler();
        self::assertStringContainsString('refunded=1', $crawler->filter('[data-action-confirm] form')->form()->getUri());
        self::assertSame($list, $crawler->filter('[data-action-confirm] a.btn-secondary')->attr('href'));

        $this->client->submit($crawler->filter('[data-action-confirm] form')->form());

        self::assertSame($list, $this->client->getResponse()->headers->get('Location'), 'back to the list it was opened from, not to the confirm page');
    }

    public function testAnOldGetLinkOfARemovedTokenRouteActsNoMore(): void
    {
        $payment = $this->payment(PaymentStatus::SUBMITTED);
        $this->client->request('POST', '/admin/payment/'.$payment->getId().'/confirm?_token=forged');
        self::assertSame(PaymentStatus::SUBMITTED, $this->row('payment', (int) $payment->getId())['status']);
    }

    public function testTheInboundConfirmPageSendsLargeInboundsToTheCommandLine(): void
    {
        for ($i = 0; $i < 11; ++$i) {
            $this->createActiveService($this->owner, $this->inbound);
        }
        $page = $this->client->request('GET', '/admin/vpn-inbound/'.$this->inbound->getId().'/regenerate-service-configs');

        $this->assertAnswered(200);
        self::assertCount(0, $page->filter('[data-action-confirm] form'), 'nothing to press');
        self::assertStringContainsString('app:inbound:regenerate-service-configs', $page->filter('[data-action-confirm]')->text());
    }

    public function testTheTestClientIsRemovedAndNoOtherClientIsTouched(): void
    {
        $before = array_keys($this->fake->clients);

        $this->confirmAction($this->client, '/admin/vpn-inbound/'.$this->inbound->getId().'/test-create-client');

        self::assertSame($before, array_keys($this->fake->clients), 'the panel holds exactly the clients it held');
        $deleted = array_values(array_filter($this->fake->requestLines(), static fn (string $line): bool => str_contains($line, '/clients/del/')));
        self::assertCount(1, $deleted);
        self::assertStringContainsString('/clients/del/test_amoobot_', $deleted[0]);
        self::assertStringContainsString('test_amoobot_', $this->landingPage());
    }

    public function testATestClientThatCannotBeRemovedIsNamedInAWarning(): void
    {
        $this->refuseDeletes();

        $this->confirmAction($this->client, '/admin/vpn-inbound/'.$this->inbound->getId().'/test-create-client');

        $names = array_filter(array_keys($this->fake->clients), static fn (int|string $name): bool => str_starts_with((string) $name, 'test_amoobot_'));
        self::assertCount(1, $names, 'it is still on the panel');
        self::assertStringContainsString((string) reset($names), $this->landingPage());
    }

    public function testTheWebDeleteGoesToThePanelFirst(): void
    {
        $url = '/admin/vpn-service/'.$this->service->getId().'/delete-on-panel';

        // The wrong name: no panel call.
        $this->client->request('POST', $url, ['confirmName' => 'wrong', '_token' => $this->csrfOf($this->client, $url)]);
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertArrayHasKey('customer_1', $this->fake->clients);
        self::assertSame(VpnServiceStatus::ACTIVE, $this->row('vpn_service', (int) $this->service->getId())['status']);

        // The panel refuses: nothing changes here.
        $token = $this->csrfOf($this->client, $url);
        $this->refuseDeletes();
        $this->client->request('POST', $url, ['confirmName' => 'customer_1', '_token' => $token]);
        self::assertSame(VpnServiceStatus::ACTIVE, $this->row('vpn_service', (int) $this->service->getId())['status'], 'not deleted here while the panel still has it');
        $this->fake->onRequest = null;

        // The name is compared as in the Mini App.
        $this->client->request('POST', $url, ['confirmName' => ' CUSTOMER_1 ', '_token' => $token]);
        self::assertArrayNotHasKey('customer_1', $this->fake->clients, 'gone from the panel');
        self::assertArrayHasKey($this->otherClient, $this->fake->clients);
        self::assertSame(VpnServiceStatus::DELETED, $this->row('vpn_service', (int) $this->service->getId())['status'], 'kept as a deleted service, as the bot does');
        self::assertSame('done', json_decode((string) $this->em->getConnection()->fetchOne("SELECT details FROM admin_audit_log WHERE action = 'service.delete' ORDER BY id DESC LIMIT 1"), true)['outcome']);
    }

    public function testEasyAdminCannotCreateOrDeleteAService(): void
    {
        $id = (int) $this->service->getId();
        $detail = $this->client->request('GET', '/admin/vpn-service/'.$id);
        self::assertCount(1, $detail->filter('a[href*="/vpn-service/'.$id.'/delete-on-panel"]'));
        self::assertCount(0, $detail->filter('form[action$="/vpn-service/'.$id.'/delete"], a.action-delete'));

        $this->client->request('GET', '/admin/vpn-service/new');
        $this->assertAnswered(403);
        $this->client->request('POST', '/admin/vpn-service/'.$id.'/delete', ['token' => 'x']);
        $this->assertAnswered(403);
        $this->batchDelete('/admin/vpn-service', VpnService::class, $id);
        $this->assertAnswered(403);
        self::assertSame(VpnServiceStatus::ACTIVE, $this->row('vpn_service', $id)['status']);
    }

    public function testADeletedServiceOffersNoDeleteOnPanel(): void
    {
        $this->service->setStatus(VpnServiceStatus::DELETED);
        $this->em->flush();

        $page = $this->client->request('GET', '/admin/vpn-service/'.$this->service->getId());

        self::assertCount(0, $page->filter('a[href*="/delete-on-panel"]'));
    }

    public function testTheServiceFormShowsEveryFieldAndWritesNone(): void
    {
        $id = (int) $this->service->getId();
        $other = $this->createUser();
        $before = $this->row('vpn_service', $id);
        $page = $this->client->request('GET', '/admin/vpn-service/'.$id.'/edit');
        $this->assertAnswered(200);
        $form = $page->filter('form[name="VpnService"]');
        self::assertCount(0, $form->filter('input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled])'), 'display only');
        self::assertSame('customer_1', $form->filter('#VpnService_username')->attr('value'));
        self::assertCount(0, $page->filter('button[name="ea[newForm][btn]"]'), 'nothing to save');

        // A forged submit of everything.
        $values = $form->form()->getPhpValues();
        $values['VpnService'] = [
            'user' => (string) $other->getId(), 'order' => '', 'panel' => '', 'inbound' => '', 'status' => 'suspended',
            'expiresAt' => '2040-01-01T00:00', 'remoteId' => 'forged', 'username' => 'forged', 'clientUuid' => 'forged', 'clientEmail' => 'forged',
            'subId' => 'forged', 'ipLimit' => '9', 'subscriptionUrl' => 'https://forged.test', 'configText' => 'forged', 'startsAt' => '2040-01-01T00:00',
            'trafficLimitGb' => '999', 'trafficUsedGb' => '0', 'trafficLimitBytes' => '999', 'trafficUsedBytes' => '0',
            '_token' => $values['VpnService']['_token'] ?? '',
        ];
        $this->client->request('POST', '/admin/vpn-service/'.$id.'/edit', $values);

        self::assertSame($before, $this->row('vpn_service', $id), 'posting them changes nothing');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function closedDeletes(): iterable
    {
        yield 'panel' => ['panel'];
        yield 'inbound' => ['inbound'];
        yield 'customer' => ['customer'];
        yield 'gateway' => ['gateway'];
    }

    #[DataProvider('closedDeletes')]
    public function testADeleteThatWouldLeaveThePanelOrTheLedgerInconsistentIsClosed(string $what): void
    {
        [$base, $table, $id, $entity] = match ($what) {
            'panel' => ['/admin/vpn-panel', 'vpn_panel', (int) $this->panel->getId(), VpnPanel::class],
            'inbound' => ['/admin/vpn-inbound', 'vpn_inbound', (int) $this->inbound->getId(), VpnInbound::class],
            'customer' => ['/admin/customer', '`user`', (int) $this->owner->getId(), User::class],
            'gateway' => ['/admin/payment-gateway', 'payment_gateway', (int) $this->createGateway()->getId(), \App\Entity\PaymentGateway::class],
            default => self::fail($what),
        };

        $page = $this->client->request('GET', $base);
        $this->assertAnswered(200);
        self::assertCount(0, $page->filter('[data-action-name="batchDelete"], form[action$="/'.$id.'/delete"], a.action-delete'), 'nothing offers it');

        $this->client->request('POST', $base.'/'.$id.'/delete', ['token' => 'x']);
        $this->assertAnswered(403);
        $this->batchDelete($base, $entity, $id);
        $this->assertAnswered(403);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE id = ?', $table), [$id]));
    }

    public function testTheBulkPriceChangeNeedsItsToken(): void
    {
        $plan = $this->createPlan(100000);
        $fields = ['adjustmentType' => 'percent', 'value' => '10', 'direction' => 'increase', 'field' => 'price', 'mode' => 'apply', 'confirm_apply' => 'on'];
        $url = '/admin/plans/bulk-adjust-prices';

        foreach ([[], ['_token' => 'forged']] as $token) {
            foreach (['apply', 'preview'] as $mode) {
                $page = $this->client->request('POST', $url, ['mode' => $mode] + $token + $fields);
                $this->assertAnswered(200);
                self::assertCount(0, $page->filter('table'), 'no preview without a token');
                self::assertSame(100000, $this->price($plan));
            }
        }

        $token = $this->csrfOf($this->client, $url, 'form.content-panel');
        $page = $this->client->request('POST', $url, ['mode' => 'preview', '_token' => $token] + $fields);
        self::assertCount(1, $page->filter('table'), 'the preview');
        self::assertSame(100000, $this->price($plan), 'a preview writes nothing');

        $this->client->request('POST', $url, ['_token' => $token] + $fields);
        self::assertSame(110000, $this->price($plan));

        $this->assertForbiddenWithout(AdminPermission::PLANS_MANAGE, $url);
    }

    public function testASettingsSaveNeedsItsToken(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name = 'reseller.floor_percent' OR key_name LIKE 'reseller.%'");

        foreach ([[], ['_token' => 'forged']] as $token) {
            $this->client->request('POST', '/admin/settings?tab=resellers', $token + ['reseller_enabled' => 'on', 'reseller_floor_percent' => '40', 'reseller_rounding' => '500']);
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM setting WHERE key_name LIKE 'reseller.%'"));
        }

        $this->saveSettings($this->client, 'resellers', ['reseller_enabled' => 'on', 'reseller_floor_percent' => '40', 'reseller_rounding' => '500']);
        self::assertGreaterThan(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM setting WHERE key_name LIKE 'reseller.%'"));
    }

    public function testThePanelInboundStepCallsThePanelOnlyOnAPostWithItsToken(): void
    {
        $url = '/admin/panel-setup/'.$this->panel->getId().'/inbounds';

        $page = $this->client->request('GET', $url);
        $this->assertAnswered(200);
        self::assertSame([], $this->fake->requests);

        $this->client->request('POST', $url, ['_token' => 'forged']);
        self::assertCount(0, $this->fake->requests, 'no panel call with a wrong token');
        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->client->submit($page->filter('form')->form());
        self::assertNotSame([], $this->fake->requests);
        self::assertNotSame(0, $this->client->getCrawler()->filter('input[name="inbounds[]"]')->count());
    }

    /**
     * EasyAdmin's "/admin?routeName=..." runs any route's controller under the route "admin": the route's own permission
     * still applies.
     */
    public function testTheDashboardRouteDoesNotOpenAPageToARoleWithoutItsPermission(): void
    {
        $urls = [
            'admin_vpn_service_delete_on_panel' => ['id' => (int) $this->service->getId()],
            'admin_vpn_inbound_regenerate_service_configs' => ['id' => (int) $this->inbound->getId()],
            'admin_vpn_panel_sync_inbounds' => ['id' => (int) $this->panel->getId()],
            'admin_wallet_adjust' => ['id' => 1],
            'admin_bulk_plan_price_adjustment' => [],
        ];
        $this->login(AdminUser::ROLE_SUPPORT);
        foreach ($urls as $route => $parameters) {
            $this->client->request('POST', '/admin?'.http_build_query(['routeName' => $route, 'routeParams' => $parameters]));
            $this->assertAnswered(403, $route);
        }

        // The query means nothing outside EasyAdmin's context: it cannot name a weaker permission for a plain route.
        $this->login(AdminUser::ROLE_ADMIN);
        $this->client->request('GET', '/admin/vpn-inbound/'.$this->inbound->getId().'/resync?routeName=admin_vpn_service_retry_move');
        $this->assertAnswered(403);
    }

    public function testSupportCanRegenerateAConfigButNotDeleteOrChangeAnInbound(): void
    {
        $this->login(AdminUser::ROLE_SUPPORT);
        $id = (int) $this->service->getId();

        $page = $this->client->request('GET', '/admin/vpn-service/'.$id);
        self::assertCount(1, $page->filter('a[href*="/regenerate-config"]'), 'the button is there');
        self::assertCount(0, $page->filter('a[href*="/delete-on-panel"]'));
        $this->confirmAction($this->client, '/admin/vpn-service/'.$id.'/regenerate-config');
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertNotSame([], $this->fake->requests, 'done');

        $this->client->request('GET', '/admin/vpn-service/'.$id.'/delete-on-panel');
        $this->assertAnswered(403);
        $this->client->request('GET', '/admin/vpn-inbound/'.$this->inbound->getId().'/regenerate-service-configs');
        $this->assertAnswered(403);
    }

    /**
     * @return array{string, AdminPermission, array<string, string>, list<string>, \Closure(): void}
     */
    private function action(string $action): array
    {
        $inbound = '/admin/vpn-inbound/'.$this->inbound->getId();
        $service = '/admin/vpn-service/'.$this->service->getId();
        $called = function (): void {
            self::assertNotSame([], $this->fake->requests, 'the panel was called');
        };

        if (str_starts_with($action, 'payment_')) {
            $payment = $this->payment('payment_retry' === $action ? PaymentStatus::PROVISIONING_FAILED : ('payment_reject_refunded' === $action ? PaymentStatus::NEEDS_REVIEW : PaymentStatus::SUBMITTED));
            $id = (int) $payment->getId();
            $amount = number_format($payment->getAmount());
            $shown = [$amount, '#'.$payment->getOrder()->getId()];

            return match ($action) {
                'payment_confirm' => ['/admin/payment/'.$id.'/confirm', AdminPermission::PAYMENTS_MODERATE, [], $shown, fn () => self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', $id)['status'])],
                'payment_retry' => ['/admin/payment/'.$id.'/retry', AdminPermission::PAYMENTS_MODERATE, [], $shown, fn () => self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', $id)['status'])],
                'payment_reject' => ['/admin/payment/'.$id.'/reject', AdminPermission::PAYMENTS_MODERATE, [], $shown, fn () => self::assertSame(PaymentStatus::REJECTED, $this->row('payment', $id)['status'])],
                'payment_reject_refunded' => ['/admin/payment/'.$id.'/reject?refunded=1', AdminPermission::PAYMENTS_MODERATE, [], $shown, fn () => self::assertNotNull($this->row('payment', $id)['refunded_at'])],
                default => self::fail($action),
            };
        }

        return match ($action) {
            'sync_panel' => ['/admin/vpn-panel/'.$this->panel->getId().'/sync-inbounds', AdminPermission::PANELS_MANAGE, [], ['Main panel'], $called],
            'resync' => [$inbound.'/resync', AdminPermission::PANELS_MANAGE, [], ['reality'], $called],
            'sync_metadata' => [$inbound.'/sync-access-metadata', AdminPermission::PANELS_MANAGE, [], ['reality'], $called],
            'test_client' => [$inbound.'/test-create-client', AdminPermission::PANELS_MANAGE, [], ['reality', 'Main panel'], $called],
            'regenerate_inbound' => [$inbound.'/regenerate-service-configs', AdminPermission::PANELS_MANAGE, [], ['2', 'reality'], $called],
            'regenerate_service' => [$service.'/regenerate-config', AdminPermission::SERVICES_ASSIST, [], ['customer_1'], $called],
            'plugin_enable' => $this->pluginAction('enable', Plugin::STATUS_DISABLED, Plugin::STATUS_ENABLED),
            'plugin_disable' => $this->pluginAction('disable', Plugin::STATUS_ENABLED, Plugin::STATUS_DISABLED),
            'broadcast_retry' => $this->broadcastAction(),
            'service_delete' => [$service.'/delete-on-panel', AdminPermission::SERVICES_DELETE, ['confirmName' => 'customer_1'], ['customer_1', 'Main panel'], fn () => self::assertSame(VpnServiceStatus::DELETED, $this->row('vpn_service', (int) $this->service->getId())['status'])],
            default => self::fail($action),
        };
    }

    /**
     * @return array{string, AdminPermission, array<string, string>, list<string>, \Closure(): void}
     */
    private function pluginAction(string $action, string $from, string $to): array
    {
        $plugin = (new Plugin())->setCode('safety_demo')->setType('other')->setName(['en' => 'Demo'])->setVersion('1.2.3')->setPath('/nowhere')->setMainClass('Nope')->setManifest([])->setStatus($from);
        $this->em->persist($plugin);
        $this->em->flush();
        $id = (int) $plugin->getId();

        return ['/admin/plugins/'.$id.'/'.$action, AdminPermission::PLUGINS_MANAGE, [], ['safety_demo', '1.2.3'], fn () => self::assertSame($to, $this->row('plugin', $id)['status'])];
    }

    /**
     * @return array{string, AdminPermission, array<string, string>, list<string>, \Closure(): void}
     */
    private function broadcastAction(): array
    {
        $broadcast = (new BroadcastMessage())->setTitle('News')->setBody('hello')->setTargetType('all')->setStatus(BroadcastMessage::STATUS_STOPPED)->setTotalRecipients(2)->setFailedCount(2);
        $this->em->persist($broadcast);
        foreach (['1', '2'] as $chat) {
            $this->em->persist((new BroadcastDelivery())->setBroadcast($broadcast)->setChatId('9200'.$chat)->setStatus(BroadcastDelivery::STATUS_FAILED)->setErrorMessage('Forbidden: bot was blocked'));
        }
        $this->em->flush();

        return ['/admin/broadcast/'.$broadcast->getId().'/retry-failed', AdminPermission::BROADCAST_SEND, [], ['2', 'News'], function (): void {
            self::assertNotSame([], $this->http->requests('/sendMessage'), 'sent again');
        }];
    }

    /** Whatever the actions write or call: the rows they touch and the panel's and Telegram's requests. */
    private function state(): string
    {
        $db = $this->em->getConnection();
        $rows = [];
        foreach (['vpn_service', 'vpn_inbound', 'vpn_panel', 'payment', '`order`', 'plugin', 'broadcast_delivery', 'plan'] as $table) {
            $rows[$table] = $db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id');
        }

        return md5((string) json_encode([$rows, count($this->fake->requests), array_keys($this->fake->clients), count($this->http->requests('/sendMessage'))]));
    }

    private function assertAnswered(int $status, string $message = ''): void
    {
        self::assertSame($status, $this->client->getResponse()->getStatusCode(), $message);
    }

    private function price(Plan $plan): int
    {
        return (int) $this->row('plan', (int) $plan->getId())['price'];
    }

    private function assertForbiddenWithout(AdminPermission $permission, string $url): void
    {
        foreach ([AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT] as $role) {
            if (in_array($permission, AdminRolePermissions::forRole($role), true)) {
                continue;
            }
            $this->login($role);
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, $url);
                $this->assertAnswered(403, sprintf('%s %s %s', $role, $method, $url));
            }
        }
    }

    private function login(string $role): void
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-'.bin2hex(random_bytes(3)).'@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
    }

    private function serviceOnPanel(string $name): VpnService
    {
        $created = self::getContainer()->get(VpnPanelDriverRegistry::class)->resolve($this->panel)->createService(new CreateVpnServiceRequest(
            username: $name,
            durationDays: 30,
            trafficLimitGb: 10,
            ipLimit: 1,
            inbound: $this->inbound,
            remoteInboundId: $this->inbound->getRemoteInboundId(),
        ), $this->panel);
        $service = $this->createActiveService($this->owner, $this->inbound)
            ->setUsername($name)->setRemoteId($created->remoteId)->setClientUuid($created->clientUuid)->setClientEmail($created->clientEmail)->setSubId($created->subId);
        $this->em->flush();

        return $service;
    }

    /** A batch delete with a valid token: only the disabled action can stop it. */
    private function batchDelete(string $base, string $entity, int $id): void
    {
        $session = $this->client->getRequest()->getSession();
        $session->set('_csrf/ea-batch-action-batchDelete-'.$entity, 'known-token');
        $session->save();
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()));

        $this->client->request('POST', $base.'/batch-delete', ['batchActionName' => 'batchDelete', 'entityFqcn' => $entity, 'batchActionCsrfToken' => 'known-token', 'batchActionEntityIds' => [(string) $id]]);
    }

    /** The page a redirect chain ends on (the flashes are shown there). */
    private function landingPage(): string
    {
        while ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }

        return html_entity_decode((string) $this->client->getResponse()->getContent());
    }

    /** The panel answers every client delete with a refusal. */
    private function refuseDeletes(): void
    {
        $this->fake->onRequest = function (string $stage, string $method, string $path): void {
            if ('before' === $stage && str_contains($path, '/clients/del/')) {
                $this->fake->script($method, $path, Fake3xuiPanel::envelope(false, null, 'refused'));
            }
        };
    }

    private function payment(string $status): Payment
    {
        $inbound = $this->createTestInbound();

        return $this->createPayment($this->createOrder($this->createUser(), $this->createPlan(150000, $inbound)), null, $status);
    }
}
