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
use App\Provisioning\Application\InboundAttachScheduler;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * F9 (M2c): the panel client's email is tg_<telegram id>_order_<order id>, and a panel's error message may quote it.
 * The customer's Telegram id never reaches vpn_service_inbound.last_error, the additional-inbound lines of the admin
 * service page (rows written before M2c are masked on display) or the scheduled run's log lines: it becomes
 * tg_[redacted]_order_<order id> (SecretRedactor::maskAccess).
 */
final class MultiInboundClientIdMaskingTest extends IntegrationTestCase
{
    private const TELEGRAM_ID = '7712345678';

    private Fake3xuiPanel $fake;
    /** @var \ArrayObject<int, array{string, array<mixed>}> what was logged */
    private \ArrayObject $logged;
    private VpnService $service;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logged = new \ArrayObject();
        self::getContainer()->set('logger', new class($this->logged) extends AbstractLogger {
            /** @param \ArrayObject<int, array{string, array<mixed>}> $records */
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        });
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $vmess = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        foreach ([$panel, $primary, $vmess] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);

        $plan = $this->createPlan(150000, $primary)->setAdditionalInbounds([$vmess]);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(self::TELEGRAM_ID), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->email = sprintf('tg_%s_order_%d', self::TELEGRAM_ID, $order->getId());

        // The client lands on the primary only; the panel refuses inbound 2, quoting the client's email.
        $this->fake->script('POST', 'panel/api/clients/add', function (string $m, string $u, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->fake->clients[$body['client']['email']] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

            return Fake3xuiPanel::envelope(true);
        });
        $this->fake->script('POST', null, Fake3xuiPanel::envelope(false, null, 'client '.$this->email.' cannot join inbound 2'), 2);
        $this->em->clear();
        self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');
        $this->service = $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order->getId()]) ?? self::fail('no service');
        self::assertSame($this->email, $this->service->getClientEmail());
    }

    public function testTheStoredErrorAndTheServicePageNeverShowTheTelegramId(): void
    {
        $stored = (string) $this->em->getConnection()->fetchOne('SELECT last_error FROM vpn_service_inbound WHERE service_id = ?', [$this->service->getId()]);
        self::assertStringNotContainsString(self::TELEGRAM_ID, $stored);
        self::assertStringContainsString('tg_[redacted]_order_'.$this->service->getOrder()?->getId(), $stored);

        self::assertStringNotContainsString(self::TELEGRAM_ID, $this->inboundLines());
    }

    public function testARowWrittenBeforeM2cIsMaskedOnTheServicePage(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE vpn_service_inbound SET last_error = ? WHERE service_id = ?', ['refused: client '.$this->email.' unknown', $this->service->getId()]);

        $lines = $this->inboundLines();

        self::assertStringNotContainsString(self::TELEGRAM_ID, $lines);
        self::assertStringContainsString('client tg_[redacted]_order_', $lines);
    }

    public function testTheScheduledRunLogsNoTelegramId(): void
    {
        $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET checked_at = '2000-01-01 00:00:00', next_attempt_at = NULL");
        $this->fake->clearScripts();
        $this->fake->script('GET', null, Fake3xuiPanel::envelope(false, null, 'database is locked while reading '.$this->email));

        $this->logged->exchangeArray([]);
        self::getContainer()->get(InboundAttachScheduler::class)->run(500, false, 50, 0);
        $log = json_encode($this->logged->getArrayCopy(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertStringContainsString('scheduled_inbound_attach_read_failed', $log);
        self::assertStringContainsString('tg_[redacted]_order_', $log, 'the error is logged, masked');
        self::assertStringNotContainsString(self::TELEGRAM_ID, $log);
        self::assertStringNotContainsString(self::TELEGRAM_ID, (string) $this->em->getConnection()->fetchOne('SELECT GROUP_CONCAT(COALESCE(last_error, \'\')) FROM vpn_service_inbound'));
    }

    private function inboundLines(): string
    {
        $admin = (new AdminUser())->setEmail('m2c-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([AdminUser::ROLE_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');
        $page = $client->request('GET', '/admin/vpn-service/'.$this->service->getId());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $lines = $page->filter('[data-service-inbound]');
        self::assertCount(1, $lines);

        return $lines->html();
    }
}
