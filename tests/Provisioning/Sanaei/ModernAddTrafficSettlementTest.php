<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\Payment;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Shop\Domain\OrderType;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * A paid add-traffic order on a modern panel, end to end through settlement (container services, fake 3x-ui that
 * validates bodies like model.Client): the production failure ("cannot unmarshal string into Go struct field
 * .allowedIPs") is gone, a panel type error is recorded on the payment with the field name, and the admin's retry of a
 * PROVISIONING_FAILED payment never adds the traffic twice, even when the panel had already applied it.
 */
final class ModernAddTrafficSettlementTest extends IntegrationTestCase
{
    private const GIB = 1073741824;
    private const UUID = '0c1d2e3f-4a5b-4c6d-8e7f-8091a2b3c4d5';
    private const EMAIL = 'tg_778899_order_7';

    private Fake3xuiPanel $fake;
    private VpnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);

        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless')->setFlow('xtls-rprx-vision');
        $this->em->persist($panel);
        $this->em->persist($inbound);
        $this->em->flush();

        // The client as GET panel/api/clients/get returns it on a current 3x-ui (allowedIPs and reverse as strings).
        $this->fake->clients[self::EMAIL] = [
            'email' => self::EMAIL, 'id' => self::UUID, 'subId' => 'subaddtraffic1', 'flow' => 'xtls-rprx-vision', 'totalGB' => 50 * self::GIB,
            'expiryTime' => (time() + 20 * 86400) * 1000, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 778899, 'comment' => 'amoobot order #7',
            'enable' => true, 'group' => '', 'reset' => 0, 'allowedIPs' => '', 'reverse' => '', 'inboundIds' => [1], 'dbId' => 1,
        ];
        $this->fake->traffic[self::EMAIL] = ['up' => 0, 'down' => 0, 'lastOnline' => 0];

        $this->service = $this->createActiveService($this->createUser('778899'), $inbound)
            ->setRemoteId((new Sanaei3xuiRemoteIdParser())->format(null, null, '1', self::UUID, self::EMAIL, 'subaddtraffic1'))
            ->setClientEmail(self::EMAIL)
            ->setClientUuid(self::UUID)
            ->setSubId('subaddtraffic1')
            ->setUsername(self::EMAIL);
        $this->em->flush();
    }

    public function testAddTrafficSucceedsOnAClientReadFromACurrentPanel(): void
    {
        $payment = $this->addTrafficPayment(10);

        $result = $this->settlement()->settle($payment, $this->paid(), 'test');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome, $result->message);
        self::assertSame(60 * self::GIB, $this->fake->clients[self::EMAIL]['totalGB']);
        self::assertSame('', $this->fake->clients[self::EMAIL]['allowedIPs'], 'allowedIPs kept (empty)');
        self::assertSame(60, (int) $this->row('vpn_service', (int) $this->service->getId())['traffic_limit_gb']);
    }

    public function testAPanelTypeErrorIsRecordedOnThePaymentWithTheFieldName(): void
    {
        $payment = $this->addTrafficPayment(10);
        $this->fake->script('POST', 'panel/api/clients/update/'.self::EMAIL, Fake3xuiPanel::envelope(false, null, 'json: cannot unmarshal string into Go struct field .allowedIPs of type []string'));

        $result = $this->settlement()->settle($payment, $this->paid(), 'test');

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $result->outcome);
        $error = (string) $this->row('payment', (int) $payment->getId())['provisioning_error'];
        self::assertStringContainsString('field "allowedIPs"', $error);
        self::assertStringContainsString('expects []string, got string', $error);
        self::assertStringNotContainsString(Fake3xuiPanel::TOKEN, $error);
        self::assertSame(50 * self::GIB, $this->fake->clients[self::EMAIL]['totalGB'], 'nothing applied');
    }

    public function testRetryingAFailedAddTrafficPaymentDoesNotAddTheTrafficTwice(): void
    {
        $payment = $this->addTrafficPayment(10);
        // The update reaches the panel and is applied, but its answer is lost: the attempt fails and is rolled back.
        $this->fake->script('POST', 'panel/api/clients/update/'.self::EMAIL, Fake3xuiPanel::networkError('Operation timed out after 15000 milliseconds'), 1, true);

        $first = $this->settlement()->settle($payment, $this->paid(), 'test');

        self::assertSame(SettlementOutcome::PROVISIONING_FAILED, $first->outcome);
        self::assertSame(60 * self::GIB, $this->fake->clients[self::EMAIL]['totalGB'], 'the panel already has the new limit');
        self::assertSame(50, (int) $this->row('vpn_service', (int) $this->service->getId())['traffic_limit_gb'], 'the database was rolled back');

        // The usage cron runs before the admin retries: it must not take the half-applied limit as the new base.
        $this->em->clear();
        $service = $this->em->find(VpnService::class, $this->service->getId()) ?? throw new \LogicException();
        self::assertFalse(self::getContainer()->get(ServiceUsageSyncService::class)->syncOne($service)->isFailed());
        self::assertSame(50, (int) $this->row('vpn_service', (int) $this->service->getId())['traffic_limit_gb'], 'limit kept while the payment is unfulfilled');

        $retry = $this->settlement()->retryProvisioning($this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException(), 1, null, 'test');

        self::assertSame(SettlementOutcome::CONFIRMED, $retry->outcome, $retry->message);
        self::assertSame(60 * self::GIB, $this->fake->clients[self::EMAIL]['totalGB'], '50 + 10, not 70');
        self::assertSame(60, (int) $this->row('vpn_service', (int) $this->service->getId())['traffic_limit_gb']);
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);

        // Once fulfilled, the sync follows the panel again.
        $this->fake->clients[self::EMAIL]['totalGB'] = 65 * self::GIB;
        $this->em->clear();
        self::getContainer()->get(ServiceUsageSyncService::class)->syncOne($this->em->find(VpnService::class, $this->service->getId()) ?? throw new \LogicException());
        self::assertSame(65, (int) $this->row('vpn_service', (int) $this->service->getId())['traffic_limit_gb']);
    }

    public function testUsageIsStillSyncedWhileAPaymentIsUnfulfilled(): void
    {
        $payment = $this->addTrafficPayment(10);
        $this->fake->script('POST', 'panel/api/clients/update/'.self::EMAIL, Fake3xuiPanel::envelope(false, null, 'database is locked'));
        $this->settlement()->settle($payment, $this->paid(), 'test');
        $this->fake->useTraffic(self::EMAIL, 7 * self::GIB);
        $this->fake->clients[self::EMAIL]['totalGB'] = 55 * self::GIB;

        $this->em->clear();
        self::getContainer()->get(ServiceUsageSyncService::class)->syncOne($this->em->find(VpnService::class, $this->service->getId()) ?? throw new \LogicException());

        $row = $this->row('vpn_service', (int) $this->service->getId());
        self::assertSame(7 * self::GIB, (int) $row['traffic_used_bytes'], 'usage still follows the panel');
        self::assertSame(50, (int) $row['traffic_limit_gb'], 'the limit waits for the retry');
        self::assertSame(VpnServiceStatus::ACTIVE, $row['status']);
    }

    private function addTrafficPayment(int $trafficGb): Payment
    {
        $order = $this->createOrder($this->service->getUser(), $this->createPlan(150000, $this->service->getInbound()), OrderType::ADD_TRAFFIC, 20000, $this->service);
        $order->setMetadata(['trafficGb' => $trafficGb]);
        $this->em->flush();
        $payment = $this->createPayment($order, null, PaymentStatus::PENDING);
        $this->fake->requests = [];

        return $payment;
    }

    private function paid(): VerifiedPayment
    {
        return VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 'tx-add-traffic', amount: '20000', currency: 'IRT'));
    }

    private function settlement(): PaymentSettlementService
    {
        return self::getContainer()->get(PaymentSettlementService::class);
    }
}
