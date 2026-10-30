<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\AdminUser;
use App\Entity\Payment;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\ServiceAdministration;
use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A service sold on a plan with additional inbounds, after provisioning (phase M2a): "Retry attach" on its admin page
 * (SERVICES_MANAGE, CSRF, rate limited, audited, idempotent, never a second client), verify-links over several
 * inbounds, the H0 regenerate path, delete (the client leaves every inbound; rows detached) and the operations that
 * stay one request by email.
 */
final class MultiInboundServiceOperationsTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;

    private Fake3xuiPanel $fake;
    /** @var array<string, mixed> the panel's inbound 2 while it is "deleted" */
    private array $vmessOnPanel;
    private VpnInbound $vmess;
    private VpnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->fake = new Fake3xuiPanel();
        $trojanId = $this->fake->addInbound(['remark' => 'trojan', 'protocol' => 'trojan', 'port' => 2083]);
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->vmess = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        $trojan = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $trojanId)->setTitle('trojan')->setProtocol('trojan');
        foreach ([$panel, $primary, $this->vmess, $trojan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);

        // Sold while inbound 2 was missing on the panel: attached to 1 and 4, inbound 2 failed.
        $plan = $this->createPlan(150000, $primary)->setAdditionalInbounds([$this->vmess, $trojan]);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->vmessOnPanel = $this->fake->inbounds[2];
        unset($this->fake->inbounds[2]);
        $this->em->clear();
        self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');
        $this->service = $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order->getId()]) ?? self::fail('no service');
        self::assertSame(['failed', 'attached'], $this->statuses());
        $this->fake->requests = [];
    }

    public function testRetryAttachOnTheServicePageAttachesTheFailedInboundOnce(): void
    {
        $this->fake->inbounds[2] = $this->vmessOnPanel; // back on the panel
        $client = $this->admin(AdminUser::ROLE_ADMIN);
        $page = $client->request('GET', '/admin/vpn-service/'.$this->service->getId());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-service-inbound="failed"', (string) $client->getResponse()->getContent());
        $form = $page->filter('form[action*="retry-attach"]');
        self::assertCount(1, $form, 'the button shows while an inbound is not attached');
        $action = (string) $form->attr('action');

        $client->request('POST', $action);
        self::assertTrue($client->getResponse()->isRedirect());

        self::assertSame(['attached', 'attached'], $this->statuses());
        self::assertSame([2, 1], array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT attempts FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$this->service->getId()])));
        self::assertSame([1, 4, 2], array_values($this->fake->clients)[0]['inboundIds']);
        self::assertNotContains('POST panel/api/clients/add', $this->fake->requestLines(), 'never a second client');
        self::assertCount(1, array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach')));
        self::assertCount(3, json_decode((string) $this->row('vpn_service', (int) $this->service->getId())['config_links'], true), 'links refreshed from the panel after the attach');
        $audit = $this->em->getConnection()->fetchAssociative("SELECT * FROM admin_audit_log WHERE action = 'service.retry_attach'");
        self::assertIsArray($audit);
        self::assertStringContainsString('"attached":1', (string) $audit['details']);

        // Again: nothing to do, no panel write.
        $this->fake->requests = [];
        $client->request('POST', $action);
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_starts_with($l, 'POST')));
        $page = $client->request('GET', '/admin/vpn-service/'.$this->service->getId());
        self::assertCount(0, $page->filter('form[action*="retry-attach"]'), 'no button once everything is attached');
    }

    public function testRetryAttachNeedsServicesManageAValidTokenAndIsRateLimited(): void
    {
        $support = $this->admin(AdminUser::ROLE_SUPPORT);
        $support->request('POST', '/admin/vpn-service/'.$this->service->getId().'/retry-attach?token=x');
        self::assertSame(403, $support->getResponse()->getStatusCode());

        $client = $this->admin(AdminUser::ROLE_ADMIN);
        $client->request('POST', '/admin/vpn-service/'.$this->service->getId().'/retry-attach?token=forged');
        self::assertSame([], $this->fake->requests, 'a bad token reaches no panel');
        self::assertSame(['failed', 'attached'], $this->statuses());

        $page = $client->request('GET', '/admin/vpn-service/'.$this->service->getId());
        $action = (string) $page->filter('form[action*="retry-attach"]')->attr('action');
        foreach (range(1, 6) as $n) {
            $client->request('POST', $action); // inbound 2 is still missing on the panel: each one fails
        }
        $attempts = (int) $this->em->getConnection()->fetchOne('SELECT attempts FROM vpn_service_inbound WHERE service_id = ? AND inbound_id = ?', [$this->service->getId(), $this->vmess->getId()]);
        self::assertSame(1 + 5, $attempts, 'five tries per 10 minutes, the sixth is refused before the panel');
        self::assertStringContainsString('inbound 2 not found', (string) $this->em->getConnection()->fetchOne('SELECT last_error FROM vpn_service_inbound WHERE inbound_id = ?', [$this->vmess->getId()]));
    }

    public function testRetryAttachWithTheSwitchOffChangesNothing(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE setting SET value = '0' WHERE key_name = 'plans.multi_inbound_enabled'");
        $this->em->clear();
        $this->service = $this->em->find(VpnService::class, $this->service->getId()) ?? self::fail('service');
        $this->fake->inbounds[2] = $this->vmessOnPanel;
        $client = $this->admin(AdminUser::ROLE_ADMIN);
        $client->request('GET', '/admin/vpn-service/'.$this->service->getId());
        self::assertStringNotContainsString('data-service-inbound', (string) $client->getResponse()->getContent(), 'the page is as before');

        $result = self::getContainer()->get(\App\Provisioning\Application\ServiceInboundAttacher::class)->retry($this->service, 'test');

        self::assertSame('switched_off', $result->outcome);
        self::assertSame([], $this->fake->requests);
    }

    public function testVerifyLinksExpectsTheAttachedInboundsAndReportsTheFailedOne(): void
    {
        [$code, $out] = $this->verify();
        self::assertSame(0, $code, $out);
        self::assertConsoleContains('INFO  client on every attached inbound: expected on inbound(s) 1, 4; inbound 2: failed after 1 attempt(s), its links are not expected', $out);

        // The panel lost inbound 4 for this client: its links are missing.
        $email = array_key_first($this->fake->clients);
        $this->fake->clients[$email]['inboundIds'] = [1];
        [$code, $out] = $this->verify();
        self::assertSame(1, $code, $out);
        self::assertConsoleContains('inbound 4 is recorded as attached but the client is not on it on the panel', $out);
    }

    public function testRegenerateStoresThePanelsLinksOfEveryAttachedInbound(): void
    {
        $this->service->setConfigLinks(['vless://stale@origin.example.com:443#old']);
        $this->em->flush();

        $result = self::getContainer()->get(ServiceConfigRegenerator::class)->regenerate($this->service, 'test');

        self::assertSame(2, $result->linkCount, 'inbounds 1 and 4, from subLinks');
        self::assertStringNotContainsString('origin.example.com', (string) $this->row('vpn_service', (int) $this->service->getId())['config_links']);
    }

    public function testOperationsStayOneRequestByEmailAndDeleteClearsEveryInbound(): void
    {
        $admin = self::getContainer()->get(ServiceAdministration::class);
        $email = (string) $this->service->getClientEmail();
        $this->fake->useTraffic($email, 2 * 1073741824);

        $admin->suspend($this->service);
        $admin->activate($this->service);
        $admin->resetUsage($this->service);
        self::getContainer()->get(ServiceUsageSyncService::class)->syncActiveServices();
        foreach ($this->fake->requestLines() as $line) {
            self::assertMatchesRegularExpression('#^(GET|POST) panel/api/clients/(get|update|resetTraffic)/'.preg_quote($email, '#').'$|^GET panel/api/clients/list$#', $line);
        }
        self::assertSame([1, 4], $this->fake->clients[$email]['inboundIds']);

        $this->fake->requests = [];
        $admin->delete($this->service);

        self::assertSame(['POST panel/api/clients/del/'.$email, 'GET panel/api/clients/get/'.$email], $this->fake->requestLines(), 'one delete by email');
        self::assertSame([], $this->fake->clients);
        self::assertSame(['detached', 'detached'], $this->statuses());
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return $this->em->getConnection()->fetchFirstColumn('SELECT status FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$this->service->getId()]);
    }

    /**
     * @return array{int, string}
     */
    private function verify(): array
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:service:verify-links'));
        $code = $tester->execute(['serviceId' => (string) $this->service->getId()]);

        return [$code, $tester->getDisplay()];
    }

    private function admin(string $role): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('m2a-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([$role]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }
}
