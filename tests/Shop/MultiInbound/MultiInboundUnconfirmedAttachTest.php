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
use App\Provisioning\Application\InboundAttachScheduler;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * F2 (M2c): the read of the client after attaching the additional inbounds fails (a panel blip). The primary was
 * confirmed before, so the paid order is provisioned and the additional inbounds are left pending (not known); the
 * scheduled run later finds them attached on the panel. Only when the primary itself was among the ids just attached
 * does provisioning still fail (no service without it).
 */
final class MultiInboundUnconfirmedAttachTest extends IntegrationTestCase
{
    private Fake3xuiPanel $fake;
    private VpnInbound $primary;
    private VpnInbound $vmess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->vmess = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        foreach ([$panel, $this->primary, $this->vmess] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
    }

    /**
     * @return iterable<string, array{\Closure(): MockResponse}>
     */
    public static function blips(): iterable
    {
        yield 'connection refused' => [static fn (): MockResponse => Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused')];
        yield 'client not found in the answer' => [static fn (): MockResponse => Fake3xuiPanel::envelope(false, null, 'record not found')];
    }

    /**
     * @param \Closure(): MockResponse $blip
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blips')]
    public function testAFailingReadAfterTheAttachLeavesThemPendingAndTheOrderProvisioned(\Closure $blip): void
    {
        [$order, $payment] = $this->paidOrder();
        // The add lands on the primary only; the attach works; every read after the attach fails.
        $this->fake->script('POST', 'panel/api/clients/add', function (string $m, string $u, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->fake->clients[$body['client']['email']] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

            return Fake3xuiPanel::envelope(true);
        });
        $this->fake->script('POST', null, function () use ($blip): MockResponse {
            $this->fake->clients[array_key_first($this->fake->clients)]['inboundIds'] = [1, 2];
            $this->fake->script('GET', null, $blip(), 10);

            return Fake3xuiPanel::envelope(true);
        }, 1, true);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        $db = $this->em->getConnection();
        self::assertSame(OrderStatus::PROVISIONED, $db->fetchOne('SELECT status FROM `order` WHERE id = ?', [$order->getId()]));
        self::assertSame([['status' => 'pending', 'attempts' => 1, 'last_error' => InboundAttachDriverInterface::NOT_CONFIRMED]], $this->rows($order));

        // The scheduled run, once the pending row is stale: found attached on the panel, no attach call.
        $this->fake->clearScripts();
        $this->fake->requests = [];
        $db->executeStatement("UPDATE vpn_service_inbound SET updated_at = '2000-01-01 00:00:00'");
        self::getContainer()->get(InboundAttachScheduler::class)->run(50, false, 50, 0);
        self::assertSame('attached', $this->rows($order)[0]['status']);
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach')));
    }

    public function testTheAttachRefusedAndTheReadFailingIsAFailedRowStillNotAFailedOrder(): void
    {
        [$order, $payment] = $this->paidOrder();
        $this->fake->script('POST', 'panel/api/clients/add', function (string $m, string $u, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->fake->clients[$body['client']['email']] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

            return Fake3xuiPanel::envelope(true);
        });
        $this->fake->script('POST', null, function (): MockResponse {
            $this->fake->script('GET', null, Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused'), 10);

            return Fake3xuiPanel::envelope(false, null, 'inbound is disabled');
        }, 1, true);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        $row = $this->rows($order)[0];
        self::assertSame(['failed', 1], [$row['status'], $row['attempts']]);
        self::assertStringContainsString('inbound is disabled', (string) $row['last_error']);
    }

    /**
     * @return array{Order, Payment}
     */
    private function paidOrder(): array
    {
        $plan = $this->createPlan(150000, $this->primary)->setAdditionalInbounds([$this->vmess]);
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
     * @return list<array{status: string, attempts: int, last_error: ?string}>
     */
    private function rows(Order $order): array
    {
        return array_map(static fn (array $r): array => ['status' => (string) $r['status'], 'attempts' => (int) $r['attempts'], 'last_error' => $r['last_error']], $this->em->getConnection()->fetchAllAssociative(
            'SELECT r.status, r.attempts, r.last_error FROM vpn_service_inbound r JOIN vpn_service s ON s.id = r.service_id WHERE s.order_id = ? ORDER BY r.id',
            [$order->getId()],
        ));
    }
}
