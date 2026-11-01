<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Payment;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * M2e E1 review R2: recording the delivery row (inside the settlement transaction) never fails or rolls back a sale.
 * Any failure but a deadlock / lock wait is logged and the sale goes on untracked: the delivery message is sent as
 * before M2e, once, and never resent. A deadlock / lock wait keeps the existing behaviour (the transaction is rolled
 * back by the server; the sale is recorded as a provisioning failure and retried).
 */
final class DeliveryTrackingFailureTest extends IntegrationTestCase
{
    private const DELIVERY = '✅ سرویس شما آماده شد';
    private const CUSTOMER = '5550001';

    private Fake3xuiPanel $fake;
    private VpnInbound $inbound;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless')->setFlow('xtls-rprx-vision');
        $this->em->persist($panel);
        $this->em->persist($this->inbound);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DROP TRIGGER IF EXISTS test_delivery_row_fails');
        parent::tearDown();
    }

    public function testAFailedDeliveryRowLeavesTheSaleConfirmedAndTheMessageSent(): void
    {
        $this->failDeliveryRow("SQLSTATE '45000' SET MESSAGE_TEXT = 'disk full (simulated)'");
        $payment = $this->sale();

        $outcome = $this->settle($payment);

        self::assertSame('confirmed', $outcome);
        $row = $this->em->getConnection()->fetchAssociative('SELECT p.status ps, o.status os, (SELECT COUNT(*) FROM vpn_service s WHERE s.order_id = o.id) services FROM payment p JOIN `order` o ON o.id = p.order_id WHERE p.id = ?', [$payment->getId()]);
        self::assertSame(['ps' => PaymentStatus::CONFIRMED, 'os' => 'provisioned', 'services' => 1], ['ps' => $row['ps'], 'os' => $row['os'], 'services' => (int) $row['services']]);
        self::assertCount(1, $this->fake->clients, 'one panel client, owned by the service: no orphan');
        self::assertSame((string) array_key_first($this->fake->clients), $this->em->getConnection()->fetchOne('SELECT client_email FROM vpn_service WHERE order_id = ?', [$payment->getOrder()->getId()]));
        self::assertSame(1, $this->deliveries(), 'the customer still gets the delivery message');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM service_notification_log WHERE type LIKE 'delivery\\_%'"), 'untracked');

        // Untracked: a retried settlement does not send it again.
        $this->em->clear();
        self::assertSame('already_processed', $this->settle($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment')));
        self::assertSame(1, $this->deliveries());
    }

    public function testADeadlockOnTheDeliveryRowKeepsTheExistingRetryBehaviour(): void
    {
        $this->failDeliveryRow("SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Deadlock found when trying to get lock (simulated)'");
        $payment = $this->sale();

        self::assertSame('provisioning_failed', $this->settle($payment));
        self::assertSame(PaymentStatus::PROVISIONING_FAILED, $this->em->getConnection()->fetchOne('SELECT status FROM payment WHERE id = ?', [$payment->getId()]));
        self::assertSame(0, $this->deliveries());
    }

    private function failDeliveryRow(string $signal): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DROP TRIGGER IF EXISTS test_delivery_row_fails');
        $connection->executeStatement("CREATE TRIGGER test_delivery_row_fails BEFORE INSERT ON service_notification_log FOR EACH ROW BEGIN IF NEW.type = 'delivery_pending' THEN SIGNAL ".$signal.'; END IF; END');
    }

    private function sale(): Payment
    {
        $user = $this->createUser(self::CUSTOMER);

        return $this->createPayment($this->createOrder($user, $this->createPlan(150000, $this->inbound)), null, PaymentStatus::SUBMITTED);
    }

    private function settle(Payment $payment): string
    {
        return self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test')->outcome->value;
    }

    private function deliveries(): int
    {
        $count = 0;
        foreach ($this->http->requests('api.telegram.org') as $request) {
            $body = json_decode((string) ($request['options']['body'] ?? ''), true) ?: [];
            $count += self::CUSTOMER === (string) ($body['chat_id'] ?? '') && str_starts_with((string) ($body['text'] ?? ''), self::DELIVERY) ? 1 : 0;
        }

        return $count;
    }
}
