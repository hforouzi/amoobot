<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A paid order of a plan with additional inbounds, through the real settlement on MariaDB and the modern driver against
 * the fake 3x-ui (docs/design/multi-inbound-plans.md 3, phase M2a): one client on every inbound, one vpn_service_inbound
 * row per additional inbound; a failing secondary inbound never fails the order (D2); the primary failing does; a
 * settlement retry adopts the client by UUID and attaches only what is missing; a legacy panel fails closed.
 */
final class MultiInboundProvisioningTest extends IntegrationTestCase
{
    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    private VpnInbound $primary;
    private VpnInbound $vmess;
    private VpnInbound $trojan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $trojanId = $this->fake->addInbound(['remark' => 'trojan', 'protocol' => 'trojan', 'port' => 2083]);
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->primary = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless')->setFlow('xtls-rprx-vision');
        $this->vmess = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        $this->trojan = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId((string) $trojanId)->setTitle('trojan')->setProtocol('trojan');
        foreach ([$this->panel, $this->primary, $this->vmess, $this->trojan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->switch(true);
    }

    public function testAPaidOrderGetsOneClientOnEveryInboundAndARowForEachAdditionalOne(): void
    {
        [$order, $payment] = $this->paidOrder();

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertCount(1, $this->fake->clients);
        $client = array_values($this->fake->clients)[0];
        self::assertSame([1, 2, 4], $client['inboundIds']);
        self::assertSame(1, count(array_keys($this->fake->requestLines(), 'POST panel/api/clients/add')));
        $service = $this->service($order);
        self::assertSame(VpnServiceStatus::ACTIVE, $service['status']);
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
        self::assertSame((string) $this->primary->getId(), (string) $service['inbound_id'], 'vpn_service.inbound stays the primary');
        self::assertSame([
            [(int) $this->vmess->getId(), '2', 'attached', 1, null],
            [(int) $this->trojan->getId(), '4', 'attached', 1, null],
        ], $this->rows((int) $service['id']));
        self::assertCount(3, json_decode((string) $service['config_links'], true), 'the panel\'s subLinks snapshot: one link per attached inbound');
    }

    public function testOneInboundThePanelNoLongerHasIsRecordedAsFailedAndTheOrderIsDelivered(): void
    {
        [$order, $payment] = $this->paidOrder();
        unset($this->fake->inbounds[2]); // deleted on the panel after the plan was set up

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame([1, 4], array_values($this->fake->clients)[0]['inboundIds']);
        $service = $this->service($order);
        $rows = $this->rows((int) $service['id']);
        self::assertSame([(int) $this->vmess->getId(), '2', 'failed', 1], array_slice($rows[0], 0, 4));
        self::assertStringContainsString('inbound 2 not found', (string) $rows[0][4]);
        self::assertSame([(int) $this->trojan->getId(), '4', 'attached', 1, null], $rows[1]);
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
        self::assertSame(VpnServiceStatus::ACTIVE, $service['status']);
    }

    public function testEverySecondaryInboundFailingStillDeliversThePrimary(): void
    {
        [$order, $payment] = $this->paidOrder();
        unset($this->fake->inbounds[2], $this->fake->inbounds[4]);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame([1], array_values($this->fake->clients)[0]['inboundIds']);
        self::assertSame(['failed', 'failed'], array_column($this->rows((int) $this->service($order)['id']), 2));
        self::assertSame(OrderStatus::PROVISIONED, $this->row('`order`', (int) $order->getId())['status']);
    }

    public function testThePrimaryFailingFailsTheProvisioningAsBefore(): void
    {
        [$order, $payment] = $this->paidOrder();
        unset($this->fake->inbounds[1]);

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $this->approve($payment));

        self::assertSame([], $this->fake->clients, 'no client without the primary');
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service_inbound'));
    }

    public function testASettlementRetryAdoptsTheClientByUuidAndAttachesOnlyWhatIsMissing(): void
    {
        [$order, $payment] = $this->paidOrder();
        // The add lands with the primary only and every read of the client fails: the first attempt fails after the
        // client was created.
        $this->fake->script('POST', 'panel/api/clients/add', function (string $m, string $u, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->fake->clients[$body['client']['email']] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);
            $this->fake->script('GET', null, Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused'), 10);

            return Fake3xuiPanel::envelope(true);
        });
        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $this->approve($payment));
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertCount(1, $this->fake->clients, 'the first attempt created the client before it failed');
        $this->fake->requests = [];
        $this->fake->clearScripts(); // the panel is reachable again

        $this->em->clear();
        $retry = self::getContainer()->get(PaymentSettlementService::class)->retryProvisioning($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');

        self::assertSame(SettlementOutcome::CONFIRMED, $retry->outcome);
        self::assertCount(1, $this->fake->clients);
        self::assertNotContains('POST panel/api/clients/add', $this->fake->requestLines(), 'adopted, never added again');
        self::assertSame([[2, 4]], $this->attachCalls(), 'only the missing ids, one call');
        self::assertSame(['attached', 'attached'], array_column($this->rows((int) $this->service($order)['id']), 2));
    }

    public function testALegacyPanelIsSentTheSingleInboundRequestOnly(): void
    {
        [$order, $payment] = $this->paidOrder();
        // Switched to legacy after the sale (M2d B2): no longer refused for its additional inbounds; the legacy driver
        // gets the plain single-inbound request (this fake speaks the modern API only, so the legacy driver's read fails).
        $this->em->getConnection()->executeStatement("UPDATE vpn_panel SET sanaei_integration_mode = 'legacy' WHERE id = ?", [$this->panel->getId()]);
        $this->em->clear();

        $this->approve($payment);

        $error = (string) $this->row('payment', (int) $payment->getId())['provisioning_error'];
        self::assertStringNotContainsString('Several inbounds need the panel in modern mode', $error);
        self::assertStringContainsString('could not be read to check for an existing client', $error, 'the legacy driver itself was asked (its own panel read failed here)');
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_contains($l, 'panel/api/clients/')), 'no modern call, no attach');
    }

    public function testAnInactiveAdditionalInboundIsRecordedWithoutAskingThePanel(): void
    {
        [$order, $payment] = $this->paidOrder();
        $this->em->getConnection()->executeStatement('UPDATE vpn_inbound SET is_active = 0 WHERE id = ?', [$this->trojan->getId()]);
        $this->em->clear();

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame([1, 2], array_values($this->fake->clients)[0]['inboundIds']);
        $rows = $this->rows((int) $this->service($order)['id']);
        self::assertSame('attached', $rows[0][2]);
        self::assertSame(['failed', 'not attached: the inbound is inactive'], [$rows[1][2], $rows[1][4]]);
    }

    public function testWithTheSwitchTurnedOffAfterTheSaleOnlyThePrimaryIsAttachedAndTheRestIsRecorded(): void
    {
        [$order, $payment] = $this->paidOrder();
        $this->switch(false);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame([1], array_values($this->fake->clients)[0]['inboundIds']);
        self::assertSame(['failed', 'failed'], array_column($this->rows((int) $this->service($order)['id']), 2));
        self::assertStringContainsString('switched off', (string) $this->rows((int) $this->service($order)['id'])[0][4]);
    }

    public function testNoErrorTextHoldsASecret(): void
    {
        [$order, $payment] = $this->paidOrder();
        $email = sprintf('tg_%s_order_%d', $order->getUser()->getTelegramAccount()?->getTelegramId(), $order->getId());
        $this->fake->script('POST', 'panel/api/clients/'.$email.'/attach', Fake3xuiPanel::envelope(false, null, 'cannot attach, see '.Fake3xuiPanel::ORIGIN.'/secret/panel/api with Bearer '.Fake3xuiPanel::TOKEN), 5);
        unset($this->fake->inbounds[2]);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        $service = $this->service($order);
        $rows = $this->rows((int) $service['id']);
        self::assertSame(['failed', 'failed'], array_column($rows, 2));
        $errors = implode("
", array_map(static fn (array $r): string => (string) $r[4], $rows));
        self::assertStringContainsString('cannot attach', $errors);
        foreach ([(string) $service['sub_id'], (string) $service['client_uuid'], Fake3xuiPanel::TOKEN, '/secret/', 'panel.example.test'] as $secret) {
            self::assertStringNotContainsString($secret, $errors);
        }
    }

    /**
     * @return array{Order, Payment}
     */
    private function paidOrder(): array
    {
        $plan = $this->createPlan(150000, $this->primary)->setAdditionalInbounds([$this->vmess, $this->trojan]);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);

        return [$order, $this->createPayment($order, null, PaymentStatus::SUBMITTED)];
    }

    private function approve(Payment $payment): SettlementOutcome
    {
        $this->em->clear();
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment');

        return self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test')->outcome;
    }

    /**
     * @return array<string, mixed>
     */
    private function service(Order $order): array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM vpn_service WHERE order_id = ?', [$order->getId()]);
        self::assertIsArray($row, 'no service for the order');

        return $row;
    }

    /**
     * @return list<array{int, string, string, int, ?string}> inbound id, remote id, status, attempts, last error
     */
    private function rows(int $serviceId): array
    {
        return array_map(static fn (array $r): array => [(int) $r['inbound_id'], (string) $r['remote_inbound_id'], (string) $r['status'], (int) $r['attempts'], $r['last_error']],
            $this->em->getConnection()->fetchAllAssociative('SELECT * FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$serviceId]));
    }

    private function switch(bool $on): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', ['plans.multi_inbound_enabled']);
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => $on ? '1' : '0', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
    }

    /**
     * @return list<mixed> the inboundIds of every attach request, in order
     */
    private function attachCalls(): array
    {
        $attaches = [];
        foreach ($this->fake->requests as $request) {
            if (str_ends_with($request['path'], '/attach')) {
                $attaches[] = $request['body']['inboundIds'];
            }
        }

        return $attaches;
    }
}
