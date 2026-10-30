<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\InboundAttachAlerts;
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * F4 (M2c): a new-service order remembers its plan's inbounds when it is created (Order::PLAN_INBOUNDS), and
 * provisioning uses that snapshot, not the plan as it is at payment time. An inbound deleted, inactive or on another
 * panel by then gets no attach (a failed row, or for a deleted one an admin alert); the primary is still delivered.
 * Orders created before the snapshot existed use the live plan.
 */
final class MultiInboundOrderSnapshotTest extends IntegrationTestCase
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
        $this->primary = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->vmess = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        $this->trojan = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId((string) $trojanId)->setTitle('trojan')->setProtocol('trojan');
        foreach ([$this->panel, $this->primary, $this->vmess, $this->trojan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
    }

    public function testTheOrderStoresThePlansInboundsAndARewriteOfItsMetadataKeepsThem(): void
    {
        [$order] = $this->paidOrder();
        $expected = ['primary' => (int) $this->primary->getId(), 'additional' => [(int) $this->vmess->getId(), (int) $this->trojan->getId()]];

        self::assertSame($expected, $this->storedSnapshot($order));
        $this->em->clear();
        $order = $this->em->find(Order::class, $order->getId()) ?? self::fail('order');
        $order->setMetadata(['inputState' => 'x']);
        $this->em->flush();
        self::assertSame($expected, $this->storedSnapshot($order), 'a caller replacing the metadata does not drop it');
        self::assertSame($expected, $order->planInboundSnapshot());

        $primary = $this->em->find(VpnInbound::class, $this->primary->getId()) ?? self::fail('primary');
        $single = $this->createOrder($this->createUser(), $this->createPlan(100000, $primary));
        self::assertSame(['primary' => (int) $this->primary->getId(), 'additional' => []], $this->storedSnapshot($single));
        $renewal = $this->createOrder($this->createUser(), $this->createPlan(100000, $primary), 'renewal');
        self::assertNull($renewal->planInboundSnapshot(), 'renewals and add-traffic orders have none');
    }

    public function testAnInboundRemovedFromThePlanAfterTheOrderIsStillAttachedAndOneAddedIsNot(): void
    {
        [$order, $payment, $plan] = $this->paidOrder([$this->vmess]);
        $plan = $this->em->find(Plan::class, $plan->getId()) ?? self::fail('plan');
        $plan->setAdditionalInbounds([$this->em->find(VpnInbound::class, $this->trojan->getId()) ?? self::fail('trojan')]);
        $this->em->flush();

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame([1, 2], array_values($this->fake->clients)[0]['inboundIds'], 'what the order was sold with, not the edited plan');
        self::assertSame([[(int) $this->vmess->getId(), 'attached']], $this->rows($order));
    }

    public function testAnInboundDeletedBeforeProvisioningIsReportedToTheAdminsAndThePrimaryIsDelivered(): void
    {
        [$order, $payment, $plan] = $this->paidOrder();
        $plan = $this->em->find(Plan::class, $plan->getId()) ?? self::fail('plan');
        $plan->setAdditionalInbounds([$this->em->find(VpnInbound::class, $this->vmess->getId()) ?? self::fail('vmess')]);
        $this->em->flush();
        $trojanId = (int) $this->trojan->getId();
        $this->em->getConnection()->executeStatement('DELETE FROM vpn_inbound WHERE id = ?', [$trojanId]);
        $before = count($this->http->telegramTexts());

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame([1, 2], array_values($this->fake->clients)[0]['inboundIds']);
        self::assertSame([[(int) $this->vmess->getId(), 'attached']], $this->rows($order));
        self::assertSame(OrderStatus::PROVISIONED, $this->em->getConnection()->fetchOne('SELECT status FROM `order` WHERE id = ?', [$order->getId()]));
        $alerts = array_values(array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_starts_with($t, InboundAttachAlerts::MISSING_TITLE)));
        self::assertCount(1, $alerts);
        self::assertStringContainsString('#'.$trojanId, $alerts[0]);
        self::assertStringNotContainsString('tg_', $alerts[0], 'no client email');
    }

    public function testAnInboundOnAnotherPanelAtProvisioningIsAFailedRowAndThePrimaryIsDelivered(): void
    {
        [$order, $payment] = $this->paidOrder();
        $other = (new VpnPanel())->setType('sanaei_3xui')->setTitle('other')->setBaseUrl('https://other.example.test/x/')->setPlainApiToken('t');
        $this->em->persist($other);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE vpn_inbound SET panel_id = ? WHERE id = ?', [$other->getId(), $this->trojan->getId()]);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame([1, 2], array_values($this->fake->clients)[0]['inboundIds']);
        self::assertSame([[(int) $this->vmess->getId(), 'attached'], [(int) $this->trojan->getId(), 'failed']], $this->rows($order));
        self::assertSame(ServiceInboundAttacher::OTHER_PANEL, $this->em->getConnection()->fetchOne('SELECT last_error FROM vpn_service_inbound WHERE inbound_id = ?', [$this->trojan->getId()]));

        // "Retry attach" never sends it to the panel (it would make the driver refuse the whole call).
        $this->fake->requests = [];
        $this->em->clear();
        $service = $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order->getId()]) ?? self::fail('service');
        $retry = self::getContainer()->get(ServiceInboundAttacher::class)->retry($service, 'test');
        self::assertSame(ServiceInboundAttacher::OTHER_PANEL, $retry->error);
        self::assertSame([], $this->fake->requests);
        self::assertSame([[(int) $this->vmess->getId(), 'attached'], [(int) $this->trojan->getId(), 'failed']], $this->rows($order));
    }

    public function testAnOrderCreatedBeforeTheSnapshotUsesTheLivePlan(): void
    {
        [$order, $payment, $plan] = $this->paidOrder([$this->vmess]);
        $this->em->getConnection()->executeStatement('UPDATE `order` SET metadata = NULL WHERE id = ?', [$order->getId()]);
        $this->em->clear();
        $plan = $this->em->find(Plan::class, $plan->getId()) ?? self::fail('plan');
        $plan->setAdditionalInbounds([$this->em->find(VpnInbound::class, $this->trojan->getId()) ?? self::fail('trojan')]);
        $this->em->flush();

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame([1, 4], array_values($this->fake->clients)[0]['inboundIds'], 'the plan as it is now');
        self::assertSame([[(int) $this->trojan->getId(), 'attached']], $this->rows($order));
    }

    /**
     * @param list<VpnInbound>|null $additional
     *
     * @return array{Order, Payment, Plan}
     */
    private function paidOrder(?array $additional = null): array
    {
        $plan = $this->createPlan(150000, $this->primary)->setAdditionalInbounds($additional ?? [$this->vmess, $this->trojan]);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);

        return [$order, $this->createPayment($order, null, PaymentStatus::SUBMITTED), $plan];
    }

    private function approve(Payment $payment): SettlementOutcome
    {
        $this->em->clear();
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment');

        return self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test')->outcome;
    }

    /**
     * @return mixed the stored metadata's snapshot
     */
    private function storedSnapshot(Order $order): mixed
    {
        $metadata = json_decode((string) $this->em->getConnection()->fetchOne('SELECT metadata FROM `order` WHERE id = ?', [$order->getId()]), true);

        return is_array($metadata) ? ($metadata[Order::PLAN_INBOUNDS] ?? null) : null;
    }

    /**
     * @return list<array{int, string}> inbound id, status
     */
    private function rows(Order $order): array
    {
        return array_map(static fn (array $r): array => [(int) $r['inbound_id'], (string) $r['status']], $this->em->getConnection()->fetchAllAssociative(
            'SELECT r.inbound_id, r.status FROM vpn_service_inbound r JOIN vpn_service s ON s.id = r.service_id WHERE s.order_id = ? ORDER BY r.id',
            [$order->getId()],
        ));
    }
}
