<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Entity\Payment;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Application\ServiceAdministration;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * An admin's extend / add traffic (bot or Mini App, ServiceAdministration) on a 3x-ui service goes to the panel first,
 * like suspend: before, only the database changed and the next usage sync (every 15 minutes) copied the panel's expiry
 * and traffic limit back over it, so the change was silently undone.
 */
final class ServiceAdministrationOnPanelTest extends IntegrationTestCase
{
    private const GB = 1073741824;

    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    private VpnInbound $primary;
    private VpnInbound $vmess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->primary = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->vmess = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        foreach ([$this->panel, $this->primary, $this->vmess] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    public function testAnAdminExtendSurvivesTheNextSync(): void
    {
        $service = $this->sell();
        $before = $service->getExpiresAt() ?? self::fail('no expiry');

        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->extend($service, 30));
        $this->sync($service);

        $expected = $before->modify('+30 days')->getTimestamp();
        self::assertEqualsWithDelta($expected, $this->expiresAt($service), 2, 'the database keeps the extension after a sync');
        self::assertEqualsWithDelta($expected * 1000, $this->client($service)['expiryTime'], 2000, 'the panel has the new expiry');
    }

    public function testAnAdminAddTrafficSurvivesTheNextSync(): void
    {
        $service = $this->sell();
        $limitGb = (int) $service->getTrafficLimitGb();

        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->addTraffic($service, 10));
        $this->sync($service);

        $row = $this->row('vpn_service', (int) $service->getId());
        self::assertSame($limitGb + 10, (int) $row['traffic_limit_gb'], 'the database keeps the added traffic after a sync');
        self::assertSame(($limitGb + 10) * self::GB, (int) $row['traffic_limit_bytes']);
        self::assertSame(($limitGb + 10) * self::GB, (int) $this->client($service)['totalGB'], 'the panel has the new limit');
    }

    public function testAPastExpiryIsExtendedFromNow(): void
    {
        $service = $this->sell();
        $service->setExpiresAt(new \DateTimeImmutable('-3 days'))->setStatus(VpnServiceStatus::EXPIRED);
        $this->em->flush();

        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->extend($service, 7));

        $expected = (new \DateTimeImmutable('+7 days'))->getTimestamp();
        self::assertEqualsWithDelta($expected, $this->expiresAt($service), 5);
        self::assertEqualsWithDelta($expected * 1000, $this->client($service)['expiryTime'], 5000);
        self::assertSame(VpnServiceStatus::EXPIRED, $this->row('vpn_service', (int) $service->getId())['status'], 'status is left as it was');
    }

    public function testExtendingASuspendedServiceKeepsItSuspendedOnThePanel(): void
    {
        $service = $this->sell();
        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->suspend($service));

        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->extend($service, 30));
        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->addTraffic($service, 10));

        self::assertFalse($this->client($service)['enable'], 'renewing the expiry must not let a suspended client back in');
        self::assertSame(VpnServiceStatus::SUSPENDED, $this->row('vpn_service', (int) $service->getId())['status']);
    }

    public function testASuspendedServiceWhoseClientCannotBeDisabledAgainIsPanelFailed(): void
    {
        $service = $this->sell();
        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->suspend($service));
        $before = $this->row('vpn_service', (int) $service->getId());
        // The renewal's update goes through; the next update (disabling the client again) is refused.
        $fake = $this->fake;
        $fake->onRequest = static function (string $when, string $method, string $path) use ($fake): void {
            if ('after' === $when && 'POST' === $method && str_starts_with($path, 'panel/api/clients/update/')) {
                $fake->onRequest = null;
                $fake->script('POST', null, Fake3xuiPanel::envelope(false, null, 'refused'), 10);
            }
        };

        self::assertSame(ServiceAdminOutcome::PANEL_FAILED, $this->administration()->extend($service, 30));

        $this->em->clear();
        self::assertSame($before['expires_at'], $this->row('vpn_service', (int) $service->getId())['expires_at']);
        self::assertSame(VpnServiceStatus::SUSPENDED, $this->row('vpn_service', (int) $service->getId())['status']);
    }

    public function testAFailingPanelLeavesTheDatabaseAsItWas(): void
    {
        $service = $this->sell();
        $before = $this->row('vpn_service', (int) $service->getId());
        $panelBefore = $this->client($service);

        $this->fake->script('POST', null, Fake3xuiPanel::envelope(false, null, 'refused'), 100);
        self::assertSame(ServiceAdminOutcome::PANEL_FAILED, $this->administration()->extend($service, 30));
        self::assertSame(ServiceAdminOutcome::PANEL_FAILED, $this->administration()->addTraffic($service, 10));
        $this->fake->clearScripts();

        $this->em->clear();
        $after = $this->row('vpn_service', (int) $service->getId());
        foreach (['expires_at', 'traffic_limit_gb', 'traffic_limit_bytes', 'status'] as $column) {
            self::assertSame($before[$column], $after[$column], $column);
        }
        self::assertSame($panelBefore['expiryTime'], $this->client($service)['expiryTime']);
        self::assertSame($panelBefore['totalGB'], $this->client($service)['totalGB']);
    }

    public function testAnUnreachablePanelIsPanelFailed(): void
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Unreachable')->setBaseUrl('https://panel.invalid:2053/secret-path');
        $this->em->persist($panel);
        $service = $this->createActiveService($this->createUser('8721'));
        $service->setPanel($panel);
        $this->em->flush();
        $before = $this->row('vpn_service', (int) $service->getId());

        self::assertSame(ServiceAdminOutcome::PANEL_FAILED, $this->administration()->extend($service, 30));
        self::assertSame(ServiceAdminOutcome::PANEL_FAILED, $this->administration()->addTraffic($service, 10));

        $this->em->clear();
        $after = $this->row('vpn_service', (int) $service->getId());
        foreach (['expires_at', 'traffic_limit_gb', 'traffic_limit_bytes'] as $column) {
            self::assertSame($before[$column], $after[$column], $column);
        }
    }

    public function testAnotherPanelTypeIsChangedInTheDatabaseOnlyAsBefore(): void
    {
        $service = $this->createActiveService($this->createUser('8722'), $this->createTestInbound());
        $expiry = $service->getExpiresAt() ?? self::fail('no expiry');
        $limitGb = (int) $service->getTrafficLimitGb();
        $renewCalls = $this->driver()->renewCalls;

        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->extend($service, 30));
        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->addTraffic($service, 10));

        self::assertSame($renewCalls, $this->driver()->renewCalls, 'no driver call for a panel that is not 3x-ui');
        self::assertEqualsWithDelta($expiry->modify('+30 days')->getTimestamp(), $this->expiresAt($service), 2);
        self::assertSame($limitGb + 10, (int) $this->row('vpn_service', (int) $service->getId())['traffic_limit_gb']);
    }

    public function testEveryInboundOfAMultiInboundServiceGetsTheChange(): void
    {
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
        $service = $this->sell([$this->vmess]);
        $email = (string) $service->getClientEmail();
        self::assertSame([1, 2], $this->fake->clients[$email]['inboundIds']);
        $before = $service->getExpiresAt() ?? self::fail('no expiry');
        $limitGb = (int) $service->getTrafficLimitGb();
        $this->fake->requests = [];

        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->extend($service, 30));
        self::assertSame(ServiceAdminOutcome::DONE, $this->administration()->addTraffic($service, 10));
        foreach ($this->fake->requestLines() as $line) {
            self::assertMatchesRegularExpression('#^(GET|POST) panel/api/clients/(get|update)/'.preg_quote($email, '#').'$#', $line, 'one client by email, as suspend');
        }
        $this->sync($service);

        // The client is one row on the panel attached to both inbounds: its new expiry and limit hold on each of them.
        $client = $this->fake->clients[$email];
        self::assertSame([1, 2], $client['inboundIds']);
        self::assertEqualsWithDelta($before->modify('+30 days')->getTimestamp() * 1000, $client['expiryTime'], 2000);
        self::assertSame(($limitGb + 10) * self::GB, (int) $client['totalGB']);
        self::assertEqualsWithDelta($before->modify('+30 days')->getTimestamp(), $this->expiresAt($service), 2);
        self::assertSame($limitGb + 10, (int) $this->row('vpn_service', (int) $service->getId())['traffic_limit_gb']);
    }

    /**
     * A service sold (and provisioned on the fake panel) on a plan of the primary inbound and $additional.
     *
     * @param list<VpnInbound> $additional
     */
    private function sell(array $additional = []): VpnService
    {
        $plan = $this->createPlan(150000, $this->primary)->setAdditionalInbounds($additional);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->em->clear();
        self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');

        $service = $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order->getId()]) ?? self::fail('no service');
        self::assertNotNull($service->getExpiresAt());
        self::assertGreaterThan(0, (int) $service->getTrafficLimitGb());

        return $service;
    }

    private function administration(): ServiceAdministration
    {
        return self::getContainer()->get(ServiceAdministration::class);
    }

    private function sync(VpnService $service): void
    {
        $this->em->clear();
        $fresh = $this->em->find(VpnService::class, $service->getId()) ?? self::fail('service');
        self::assertSame('updated', self::getContainer()->get(ServiceUsageSyncService::class)->syncOne($fresh)->outcome);
        $this->em->clear();
    }

    private function expiresAt(VpnService $service): int
    {
        return (int) strtotime((string) $this->row('vpn_service', (int) $service->getId())['expires_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function client(VpnService $service): array
    {
        return $this->fake->clients[(string) $service->getClientEmail()] ?? self::fail('no client on the panel');
    }
}
