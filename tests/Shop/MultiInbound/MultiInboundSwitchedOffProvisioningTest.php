<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\VpnProvisioningService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * F3 (M2c): with plans.multi_inbound_enabled off, a paid order of a plan with additional inbounds always provisions the
 * primary, on any panel type (a panel whose driver cannot attach included), exactly as a single-inbound plan; the
 * additional inbounds are not sent to the panel and get failed rows with a clear reason. With the switch on, such a
 * panel fails closed (PROVISIONING_FAILED and the admins' alert), as designed.
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

    public function testSwitchOnAPanelThatCannotAttachFailsClosedAndAlertsTheAdmins(): void
    {
        [$order, $payment] = $this->paidOrderOn($this->createTestInbound());
        $this->switch(true);
        $before = count($this->http->telegramTexts());

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $this->approve($payment));

        self::assertSame(0, $this->driver()->createCalls, 'never the primary alone');
        self::assertSame(0, $this->countServicesForOrder($order));
        self::assertNotEmpty(array_filter(array_slice($this->http->telegramTexts(), $before), static fn (string $t): bool => str_contains($t, 'Several inbounds need the panel in modern mode')), 'the admins are alerted');
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
