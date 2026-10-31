<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\VpnProvisioningService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Shared\Infrastructure\RedactingLogger;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\CapturesErrorLog;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * M2d B3 (finishes M2c F9): the customer's Telegram id never reaches a log line or a stored error. Client emails are
 * tg_<telegram id>_order_<n>. Every error_log() line of the app goes through SafeErrorLog, every PSR record through
 * RedactingLogger (both SecretRedactor::forLog()); the locations the review named are fixed at the source too.
 */
final class TelegramIdLogRedactionTest extends IntegrationTestCase
{
    use CapturesErrorLog;
    use Fake3xuiWiring;

    private const TELEGRAM_ID = '5550123987';

    public function testForLogMasksTheTelegramIdAndNothingElse(): void
    {
        self::assertSame('client "tg_[redacted]_order_42" order_id=42', SecretRedactor::forLog('client "tg_5550123987_order_42" order_id=42'));
        self::assertSame('chat_id="[redacted]" actor_id=[redacted] user_id=5550123', SecretRedactor::forLog('chat_id="5550123987" actor_id=5550123987 user_id=5550123'));
        self::assertSame('{"telegram_id":[redacted],"service_id":77}', SecretRedactor::forLog('{"telegram_id":5550123987,"service_id":77}'));
        self::assertSame('custom_user_7 tg_name panel_id=12', SecretRedactor::forLog('custom_user_7 tg_name panel_id=12'), 'other text is unchanged');
    }

    public function testEveryLogRecordOfTheAppGoesThroughTheRedactingLogger(): void
    {
        self::assertInstanceOf(RedactingLogger::class, self::getContainer()->get(LoggerInterface::class));

        self::getContainer()->get(LoggerInterface::class)->warning('client tg_5550123987_order_9 refused', ['email' => 'tg_5550123987_order_9', 'telegram_id' => self::TELEGRAM_ID, 'nested' => ['chat_id' => (int) self::TELEGRAM_ID], 'exception' => new \RuntimeException('A client named "tg_5550123987_order_9" exists')]);

        $text = $this->recorder()->text();
        self::assertStringNotContainsString(self::TELEGRAM_ID, $text);
        self::assertStringContainsString('tg_[redacted]_order_9', $text, 'the order id stays for support');
    }

    public function testTheProvisioningConfigLineHasNoTelegramId(): void
    {
        $order = $this->createOrder($this->createUser(self::TELEGRAM_ID), $this->createPlan(100000, $this->createTestInbound()));

        $log = $this->captureErrorLog(function () use ($order): void {
            self::getContainer()->get(VpnProvisioningService::class)->provisionOrder($order, ['source' => 'test']);
        });

        self::assertStringContainsString('provisioning_config', $log);
        self::assertStringContainsString('final_username="tg_[redacted]_order_'.$order->getId().'"', $log);
        self::assertStringNotContainsString(self::TELEGRAM_ID, $log);
    }

    public function testTheModernDriversAdoptionLinesHaveNoTelegramIdWhateverTheLogger(): void
    {
        $this->fake = new Fake3xuiPanel();
        $recorder = new RecordingLogger(new NullLogger());
        $driver = new Sanaei3xuiModernDriver(new ModernClientsApi($this->modernHttp()), new Sanaei3xuiRemoteIdParser(), $recorder);
        $panel = $this->modernPanel();
        $request = new CreateVpnServiceRequest(username: 'tg_'.self::TELEGRAM_ID.'_order_42', durationDays: 30, trafficLimitGb: 50, inbound: $this->fakeInbound($panel), remoteInboundId: '1', meta: ['orderId' => 42], clientUuid: '7f3c9a10-5b2e-4c8d-9e1f-0a1b2c3d4e5f');
        // The add is applied and its answer lost: create_adopted_after_failure.
        $this->fake->script('POST', 'panel/api/clients/add', function (string $m, string $u, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->fake->clients[$body['client']['email']] = array_merge(['subId' => 'sub42', 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

            return Fake3xuiPanel::networkError('Operation timed out after 10000 milliseconds');
        });
        $driver->createService($request, $panel);
        // A retried settlement finds it: existing_client_found.
        $driver->findExistingService($request, $panel);

        self::assertSame(['sanaei_modern_create_adopted_after_failure', 'sanaei_modern_existing_client_found'], array_values(array_intersect(array_column($recorder->records, 'message'), ['sanaei_modern_create_adopted_after_failure', 'sanaei_modern_existing_client_found'])));
        self::assertStringNotContainsString(self::TELEGRAM_ID, $recorder->text());
        self::assertStringContainsString('tg_[redacted]_order_42', $recorder->text());
    }

    public function testTheSettlementAbortedLineHasNoTelegramId(): void
    {
        $user = $this->createUser(self::TELEGRAM_ID);
        $order = (new Order())->setUser($user)->setType(OrderType::WALLET_TOPUP)->setAmount(50000)->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->persist($order);
        $this->em->flush();
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->em->getConnection()->insert('setting', ['key_name' => 'wallet.enabled', 'value' => 'true', 'type' => 'string', 'created_at' => '2026-01-01 00:00:00']);
        // The settlement's flush fails with a message that quotes a client email.
        $failing = new class {
            public function onFlush(): void
            {
                throw new \RuntimeException('A client named "tg_5550123987_order_7" already exists on the panel');
            }
        };
        $this->em->getEventManager()->addEventListener([Events::onFlush], $failing);
        try {
            self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test');
        } finally {
            $this->em->getEventManager()->removeEventListener([Events::onFlush], $failing);
        }

        self::assertContains('Payment settlement aborted before fulfilment.', array_column($this->recorder()->records, 'message'));
        self::assertStringNotContainsString(self::TELEGRAM_ID, $this->recorder()->text());
    }

    public function testAFullMultiInboundSettlementLogsAndStoresNoTelegramId(): void
    {
        $fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $vmess = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        $gone = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('9')->setTitle('deleted on the panel')->setProtocol('vmess');
        foreach ([$panel, $primary, $vmess, $gone] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
        $plan = $this->createPlan(150000, $primary)->setAdditionalInbounds([$vmess, $gone]);
        $this->em->flush();
        $user = $this->createUser(self::TELEGRAM_ID);
        $multi = $this->createOrder($user, $plan);
        $multiPayment = $this->createPayment($multi, null, PaymentStatus::SUBMITTED);
        $single = $this->createOrder($user, $this->createPlan(90000, $this->createTestInbound()));
        $singlePayment = $this->createPayment($single, null, PaymentStatus::SUBMITTED);

        $log = $this->captureErrorLog(function () use ($multiPayment, $singlePayment): void {
            foreach ([$multiPayment, $singlePayment] as $payment) {
                $this->em->clear();
                $payment = $this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment');
                self::assertSame(SettlementOutcome::CONFIRMED, self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test')->outcome);
            }
        });

        // The panel refused the add with every id (inbound 9 is gone), the attach pass after the commit refused it too.
        self::assertSame(['attached', 'failed'], $this->em->getConnection()->fetchFirstColumn('SELECT r.status FROM vpn_service_inbound r JOIN vpn_service s ON s.id = r.service_id WHERE s.order_id = ? ORDER BY r.id', [$multi->getId()]));
        self::assertStringNotContainsString(self::TELEGRAM_ID, $log, 'error_log lines');
        self::assertStringNotContainsString(self::TELEGRAM_ID, $this->recorder()->text(), 'log records');
        $db = $this->em->getConnection();
        $stored = [
            ...$db->fetchFirstColumn('SELECT last_error FROM vpn_service_inbound'),
            ...$db->fetchFirstColumn("SELECT CONCAT_WS(' ', provisioning_error, admin_note, review_reason) FROM payment"),
            ...$db->fetchFirstColumn('SELECT metadata FROM `order`'),
        ];
        self::assertStringNotContainsString(self::TELEGRAM_ID, implode("\n", array_map('strval', $stored)), 'stored errors and order metadata');
    }

    private function recorder(): RecordingLogger
    {
        return self::getContainer()->get(RecordingLogger::class);
    }
}
