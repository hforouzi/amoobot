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
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * M2d B1: the settlement's critical section (order and payment rows locked) does only what delivers the primary: one add
 * with every inbound id and the read back. Every other attach call runs after the commit, with no transaction open, in
 * one pass of at most ServiceInboundAttacher::AFTER_SALE_BUDGET_SECONDS (every call of the pass included) and at most
 * Sanaei3xuiModernDriver::MAX_SINGLE_ATTACHES_IN_A_PASS one-by-one calls after a refused batch. What is left is pending
 * for the scheduled run; nothing there changes the order or the payment. Time is a MockClock the fake panel advances.
 */
final class MultiInboundAfterSaleAttachTest extends IntegrationTestCase
{
    private Fake3xuiPanel $fake;
    private MockClock $clock;
    private VpnInbound $primary;
    /** @var list<VpnInbound> */
    private array $additional = [];
    /** @var list<bool> whether a transaction was open at each attach call */
    private array $inTransaction = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-30 12:00:00');
        Clock::set($this->clock);
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->em->persist($panel);
        $this->primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->em->persist($this->primary);
        foreach ([2, ...array_map(fn (int $n): int => $this->fake->addInbound(['remark' => 'extra'.$n]), range(1, 4))] as $remoteId) {
            $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $remoteId)->setTitle('extra '.$remoteId)->setProtocol('vmess');
            $this->em->persist($inbound);
            $this->additional[] = $inbound;
        }
        $this->em->flush();
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testOnlyTheAddAndTheReadRunUnderTheLocksTheAttachesAfterTheCommit(): void
    {
        [$order, $payment] = $this->paidOrder(2);
        $this->primaryOnlyAdd();
        $this->fake->script('POST', null, function (): MockResponse {
            $this->inTransaction[] = $this->em->getConnection()->isTransactionActive();

            return Fake3xuiPanel::envelope(true);
        }, 10, true);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertSame([false], $this->inTransaction, 'one attach call, with no transaction (and so no row lock) open');
        self::assertSame([['attached', 1, null], ['attached', 1, null]], $this->rows($order));
        self::assertSame([1, 2, (int) $this->additional[1]->getRemoteInboundId()], array_values($this->fake->clients)[0]['inboundIds']);
        $service = $this->em->getRepository(VpnService::class)->findOneBy(['order' => $order->getId()]) ?? self::fail('service');
        self::assertCount(3, $service->getConfigLinks() ?? [], 'the links were refreshed after the attach, before the delivery message');
    }

    public function testAtMostThreeOneByOneAttachesAfterTheSaleTheRestPending(): void
    {
        [$order, $payment] = $this->paidOrder(5);
        $this->primaryOnlyAdd();
        $this->refusedBatchThenSingles(0);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertCount(1 + Sanaei3xuiModernDriver::MAX_SINGLE_ATTACHES_IN_A_PASS, $this->attachCalls(), 'the refused batch, then three single ones');
        self::assertNotContains(true, $this->inTransaction);
        self::assertSame(OrderStatus::PROVISIONED, $this->em->getConnection()->fetchOne('SELECT status FROM `order` WHERE id = ?', [$order->getId()]));
        self::assertSame([
            ['attached', 1, null], ['attached', 1, null], ['attached', 1, null],
            ['pending', 0, InboundAttachDriverInterface::NOT_TRIED], ['pending', 0, InboundAttachDriverInterface::NOT_TRIED],
        ], $this->rows($order));
    }

    public function testThePassStopsAtItsTimeLimitTheRestPending(): void
    {
        [$order, $payment] = $this->paidOrder(3);
        $this->primaryOnlyAdd();
        $this->refusedBatchThenSingles(10); // each single attach takes 10 s: two start within the 15 s of the pass

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertCount(1 + 2, $this->attachCalls());
        $lines = $this->fake->requestLines();
        $afterLastAttach = array_slice($lines, (int) array_key_last(array_filter($lines, static fn (string $l): bool => str_ends_with($l, '/attach'))) + 1);
        self::assertSame([], $afterLastAttach, 'out of time: nothing more is sent (no read back, no link refresh)');
        self::assertSame([
            ['pending', 1, InboundAttachDriverInterface::NOT_CONFIRMED], ['pending', 1, InboundAttachDriverInterface::NOT_CONFIRMED],
            ['pending', 0, InboundAttachDriverInterface::NOT_TRIED],
        ], $this->rows($order));
        self::assertSame(OrderStatus::PROVISIONED, $this->em->getConnection()->fetchOne('SELECT status FROM `order` WHERE id = ?', [$order->getId()]));
    }

    public function testAPanelFailingAfterTheCommitNeverChangesTheOrderOrThePayment(): void
    {
        [$order, $payment] = $this->paidOrder(2);
        $this->primaryOnlyAdd();
        // Delivered, then the panel goes away: every attach of the pass fails to connect.
        $this->fake->script('POST', null, Fake3xuiPanel::networkError('Failed to connect to panel.example.test port 2053: Connection refused'), 10);

        $result = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->reload($payment), 1, null, 'test');

        self::assertSame(SettlementOutcome::CONFIRMED, $result->outcome);
        self::assertSame(PaymentStatus::CONFIRMED, $this->em->getConnection()->fetchOne('SELECT status FROM payment WHERE id = ?', [$payment->getId()]));
        self::assertSame(OrderStatus::PROVISIONED, $this->em->getConnection()->fetchOne('SELECT status FROM `order` WHERE id = ?', [$order->getId()]));
        self::assertSame([['failed', 1], ['failed', 1]], array_map(static fn (array $r): array => [$r[0], $r[1]], $this->rows($order)), 'left for the scheduled run');
    }

    public function testAnAfterSalePassInsideATransactionDoesNothing(): void
    {
        [$order, $payment] = $this->paidOrder(1);
        $this->primaryOnlyAdd();
        $this->fake->script('POST', null, Fake3xuiPanel::envelope(false, null, 'must not be called'), 10);
        $db = $this->em->getConnection();
        $db->insert('setting', ['key_name' => 'plans.attach_retry_enabled', 'value' => '0', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
        // Provision without the settlement's pass, then call it from inside a transaction.
        $service = $this->provisionWithoutPass($payment);
        $db->beginTransaction();
        try {
            $retry = self::getContainer()->get(ServiceInboundAttacher::class)->afterSale($service);
        } finally {
            $db->rollBack();
        }

        self::assertSame('not_applicable', $retry->outcome);
        self::assertSame([], $this->attachCalls());
    }

    private function provisionWithoutPass(Payment $payment): VpnService
    {
        $order = $this->em->find(Order::class, $payment->getOrder()->getId()) ?? self::fail('order');
        $service = self::getContainer()->get(\App\Provisioning\Application\VpnProvisioningService::class)->provisionOrder($order);
        $this->em->flush();

        return $service;
    }

    private function primaryOnlyAdd(): void
    {
        $this->fake->script('POST', 'panel/api/clients/add', function (string $m, string $u, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->fake->clients[$body['client']['email']] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

            return Fake3xuiPanel::envelope(true);
        });
        $this->inTransaction = [];
    }

    private function refusedBatchThenSingles(int $secondsPerSingle): void
    {
        $this->fake->script('POST', null, function (): MockResponse {
            $this->inTransaction[] = $this->em->getConnection()->isTransactionActive();

            return Fake3xuiPanel::envelope(false, null, 'one inbound is broken');
        });
        $this->fake->script('POST', null, function () use ($secondsPerSingle): MockResponse {
            $this->inTransaction[] = $this->em->getConnection()->isTransactionActive();
            $this->clock->sleep($secondsPerSingle);

            return Fake3xuiPanel::envelope(true);
        }, 10, true);
    }

    /**
     * @return array{Order, Payment}
     */
    private function paidOrder(int $additional): array
    {
        $plan = $this->createPlan(150000, $this->primary)->setAdditionalInbounds(array_slice($this->additional, 0, $additional));
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);

        return [$order, $this->createPayment($order, null, PaymentStatus::SUBMITTED)];
    }

    private function reload(Payment $payment): Payment
    {
        $this->em->clear();

        return $this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment');
    }

    private function approve(Payment $payment): SettlementOutcome
    {
        return self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->reload($payment), 1, null, 'test')->outcome;
    }

    /**
     * @return list<string>
     */
    private function attachCalls(): array
    {
        return array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach')));
    }

    /**
     * @return list<array{string, int, ?string}> status, attempts, last error
     */
    private function rows(Order $order): array
    {
        return array_map(static fn (array $r): array => [(string) $r['status'], (int) $r['attempts'], $r['last_error']], $this->em->getConnection()->fetchAllAssociative(
            'SELECT r.status, r.attempts, r.last_error FROM vpn_service_inbound r JOIN vpn_service s ON s.id = r.service_id WHERE s.order_id = ? ORDER BY r.id',
            [$order->getId()],
        ));
    }
}
