<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\InboundAttachAlerts;
use App\Provisioning\Application\InboundAttachScheduler;
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Provisioning\Application\VpnProvisioningService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * F3 (M2c): with plans.multi_inbound_enabled off, a paid order of a plan with additional inbounds always provisions the
 * primary, on any panel type (a panel whose driver cannot attach included), exactly as a single-inbound plan; the
 * additional inbounds are not sent to the panel and get failed rows with a clear reason. With the switch on and a panel
 * that cannot attach (it left modern mode after the payment; M2d B2) the same: the primary is delivered, every additional
 * inbound is failed with VpnProvisioningService::NOT_MODERN (after the per-inbound reasons) and the admins get one alert.
 */
final class MultiInboundSwitchedOffProvisioningTest extends IntegrationTestCase
{
    public function testSwitchOffAPanelThatCannotAttachDeliversThePrimary(): void
    {
        [$order, $payment] = $this->paidOrderOn($this->createTestInbound());
        $this->switch(false);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame(1, $this->driver()->createCalls);
        self::assertSame(OrderStatus::PROVISIONED, $this->em->getConnection()->fetchOne('SELECT status FROM `order` WHERE id = ?', [$order->getId()]));
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT r.status, r.last_error FROM vpn_service_inbound r JOIN vpn_service s ON s.id = r.service_id WHERE s.order_id = ?', [$order->getId()]);
        self::assertSame([['status' => 'failed', 'last_error' => VpnProvisioningService::SWITCHED_OFF]], $rows);
    }

    public function testSwitchOnAPanelThatCannotAttachDeliversThePrimaryAndAlertsTheAdminsOnce(): void
    {
        // M2d B2: the panel stopped being modern (or is another type) after the order was paid; the switch is on.
        [$order, $payment] = $this->paidOrderOn($this->createTestInbound());
        $this->switch(true);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment), 'a paid order is never failed for its additional inbounds');

        self::assertSame(1, $this->driver()->createCalls);
        self::assertSame([], $this->driver()->createRequests[0]->additionalInbounds, 'the panel gets exactly the single-inbound request');
        self::assertSame(OrderStatus::PROVISIONED, $this->em->getConnection()->fetchOne('SELECT status FROM `order` WHERE id = ?', [$order->getId()]));
        self::assertSame([['status' => 'failed', 'last_error' => VpnProvisioningService::NOT_MODERN]], $this->rowsOf($order));

        $before = count($this->http->telegramTexts());
        $scheduler = self::getContainer()->get(InboundAttachScheduler::class);
        $scheduler->run(50);
        $scheduler->run(50);
        $alerts = array_values(array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_contains($t, InboundAttachAlerts::FAILED_TITLE)));
        self::assertCount(1, $alerts, 'one alert, however many runs');
        self::assertStringContainsString('not in modern mode', $alerts[0]);
    }

    public function testThePerInboundReasonsComeBeforeThePanelMode(): void
    {
        [$order, $payment] = $this->paidOrderOn($this->createTestInbound());
        $inactive = (new VpnInbound())->setPanel($this->em->find(VpnInbound::class, $order->getPlan()?->getInbound()?->getId())?->getPanel() ?? self::fail('panel'))
            ->setRemoteInboundId('77')->setTitle('off')->setProtocol('vmess');
        $this->em->persist($inactive);
        $this->em->flush();
        // The order's snapshot names two additional inbounds; one of them is inactive by the time it is paid.
        $metadata = $order->getMetadata() ?? [];
        $metadata[Order::PLAN_INBOUNDS]['additional'][] = (int) $inactive->getId();
        $this->em->getConnection()->executeStatement('UPDATE `order` SET metadata = ? WHERE id = ?', [json_encode($metadata), $order->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE vpn_inbound SET is_active = 0 WHERE id = ?', [$inactive->getId()]);
        $this->switch(true);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame([
            ['status' => 'failed', 'last_error' => VpnProvisioningService::NOT_MODERN],
            ['status' => 'failed', 'last_error' => 'not attached: the inbound is inactive'],
        ], $this->rowsOf($order));
    }

    /**
     * @return list<array{status: string, last_error: ?string}>
     */
    private function rowsOf(Order $order): array
    {
        /** @var list<array{status: string, last_error: ?string}> */
        return $this->em->getConnection()->fetchAllAssociative('SELECT r.status, r.last_error FROM vpn_service_inbound r JOIN vpn_service s ON s.id = r.service_id WHERE s.order_id = ? ORDER BY r.id', [$order->getId()]);
    }

    public function testSwitchOffAModernPanelSendsExactlyTheSingleInboundRequests(): void
    {
        $fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->em->persist($panel);
        $this->em->persist($primary);
        $this->em->flush();
        [, $single] = $this->paidOrderOn($primary, false);
        [, $multi] = $this->paidOrderOn($primary);
        $this->switch(false);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($single));
        $normalise = static fn (array $lines): array => preg_replace(['/tg_\d+_order_\d+/', '#subLinks/\w+#'], ['<email>', 'subLinks/<sub>'], $lines);
        $singleLines = $normalise($fake->requestLines());
        $fake->requests = [];
        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($multi));

        self::assertSame($singleLines, $normalise($fake->requestLines()), 'the same requests as a plan without additional inbounds');
        self::assertSame([[1], [1]], array_values(array_map(static fn (array $c): array => $c['inboundIds'], $fake->clients)));
    }

    public function testRetryAttachAfterTheSwitchIsOnAgainAttachesTheInboundsLeftOut(): void
    {
        $fake = new Fake3xuiPanel();
        $trojanId = $fake->addInbound(['remark' => 'trojan', 'protocol' => 'trojan', 'port' => 2083]);
        $this->http->on('panel.example.test', $fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $trojan = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $trojanId)->setTitle('trojan')->setProtocol('trojan');
        foreach ([$panel, $primary, $trojan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->switch(true);
        $plan = $this->createPlan(150000, $primary)->setAdditionalInbounds([$trojan]);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->switch(false);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));
        $service = $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order->getId()]) ?? self::fail('no service');
        self::assertSame([[1]], array_values(array_map(static fn (array $c): array => $c['inboundIds'], $fake->clients)));
        self::assertSame(['failed', VpnProvisioningService::SWITCHED_OFF], array_values($this->em->getConnection()->fetchAssociative('SELECT status, last_error FROM vpn_service_inbound WHERE service_id = ?', [$service->getId()]) ?: []));

        $this->switch(true);
        $retry = self::getContainer()->get(ServiceInboundAttacher::class)->retry($service, 'admin');

        self::assertSame(1, $retry->attached);
        self::assertSame([[1, $trojanId]], array_values(array_map(static fn (array $c): array => $c['inboundIds'], $fake->clients)));
        self::assertSame('attached', $this->em->getConnection()->fetchOne('SELECT status FROM vpn_service_inbound WHERE service_id = ?', [$service->getId()]));
    }

    /**
     * @return array{Order, Payment}
     */
    private function paidOrderOn(VpnInbound $primary, bool $withAdditional = true): array
    {
        $this->switch(true); // the order is taken while the plan is sold
        $primary = $this->em->find(VpnInbound::class, $primary->getId()) ?? self::fail('primary');
        $second = (new VpnInbound())->setPanel($primary->getPanel())->setRemoteInboundId((string) random_int(10, 99999))->setTitle('second')->setProtocol('vmess');
        $this->em->persist($second);
        $plan = $this->createPlan(150000, $primary);
        if ($withAdditional) {
            $plan->setAdditionalInbounds([$second]);
        }
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

    private function switch(bool $on): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', ['plans.multi_inbound_enabled']);
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => $on ? '1' : '0', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
    }
}
