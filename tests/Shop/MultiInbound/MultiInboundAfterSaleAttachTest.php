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
 * F5 (M2c): the additional inbounds attached at the sale run under the settlement's locks, so that work is bounded:
 * at most Sanaei3xuiModernDriver::INLINE_MAX_SINGLE_ATTACHES one-by-one calls after a refused batch, and no new call
 * once INLINE_ATTACH_BUDGET_SECONDS have passed. What is left is pending for the scheduled run; the order is
 * provisioned. Time is a MockClock that the fake panel advances, so the test does not wait.
 */
final class MultiInboundInlineBudgetTest extends IntegrationTestCase
{
    private Fake3xuiPanel $fake;
    private MockClock $clock;
    private VpnInbound $primary;
    /** @var list<VpnInbound> */
    private array $additional = [];

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

    public function testAtMostThreeOneByOneAttachesAtTheSaleTheRestPending(): void
    {
        [$order, $payment] = $this->paidOrder(5);
        $this->primaryOnlyAddAndRefusedBatch(0);

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertCount(1 + Sanaei3xuiModernDriver::INLINE_MAX_SINGLE_ATTACHES, $this->attachCalls(), 'the refused batch, then three single ones');
        self::assertSame(OrderStatus::PROVISIONED, $this->em->getConnection()->fetchOne('SELECT status FROM `order` WHERE id = ?', [$order->getId()]));
        self::assertSame([
            ['attached', 1, null], ['attached', 1, null], ['attached', 1, null],
            ['pending', 0, InboundAttachDriverInterface::NOT_TRIED], ['pending', 0, InboundAttachDriverInterface::NOT_TRIED],
        ], $this->rows($order));
    }

    public function testNoNewCallAfterTheTimeBudgetTheRestPending(): void
    {
        [$order, $payment] = $this->paidOrder(3);
        $this->primaryOnlyAddAndRefusedBatch(12); // each single attach takes 12 s: two fit in 20 s

        self::assertSame(SettlementOutcome::CONFIRMED, $this->approve($payment));

        self::assertCount(1 + 2, $this->attachCalls());
        $lines = $this->fake->requestLines();
        $afterLastAttach = array_slice($lines, (int) array_key_last(array_filter($lines, static fn (string $l): bool => str_ends_with($l, '/attach'))) + 1);
        self::assertSame([], array_values(array_filter($afterLastAttach, static fn (string $l): bool => str_starts_with($l, 'GET panel/api/clients/get/'))), 'out of time: the client is not read back');
        self::assertSame([
            ['pending', 1, InboundAttachDriverInterface::NOT_CONFIRMED], ['pending', 1, InboundAttachDriverInterface::NOT_CONFIRMED],
            ['pending', 0, InboundAttachDriverInterface::NOT_TRIED],
        ], $this->rows($order));
        self::assertSame(OrderStatus::PROVISIONED, $this->em->getConnection()->fetchOne('SELECT status FROM `order` WHERE id = ?', [$order->getId()]));
    }

    private function primaryOnlyAddAndRefusedBatch(int $secondsPerSingle): void
    {
        $this->fake->script('POST', 'panel/api/clients/add', function (string $m, string $u, array $options): MockResponse {
            $body = json_decode((string) $options['body'], true);
            $this->fake->clients[$body['client']['email']] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

            return Fake3xuiPanel::envelope(true);
        });
        $this->fake->script('POST', null, Fake3xuiPanel::envelope(false, null, 'one inbound is broken'));
        $this->fake->script('POST', null, function () use ($secondsPerSingle): MockResponse {
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

    private function approve(Payment $payment): SettlementOutcome
    {
        $this->em->clear();
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment');

        return self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test')->outcome;
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
