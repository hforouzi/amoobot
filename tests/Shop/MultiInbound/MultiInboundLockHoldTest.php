<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Payment;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\ServiceInboundAttacher;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * M2d B1, in real time with a real second process: a slow panel (every call DELAY_MS) and the path with the most
 * calls (the add with every id refused, the primary added alone, the batch attach refused, singles). While the
 * settlement runs, another process takes FOR UPDATE on the order (a second callback, a second click) with a lock wait
 * of LOCK_WAIT_S. Only the calls that deliver the primary may hold the row: on the code before M2d the attaches ran under
 * the lock too (12 calls, about 36 s here) and that process failed with 1205; now it waits for the 7 primary calls only
 * and gets the row. The pass after the commit is bounded by ServiceInboundAttacher::AFTER_SALE_BUDGET_SECONDS.
 */
#[Group('real-time')]
final class MultiInboundLockHoldTest extends IntegrationTestCase
{
    private const DELAY_MS = 3000;
    private const LOCK_WAIT_S = 30;
    /** adopt look-up (get, list), add refused, get, add primary only, get, subLinks */
    private const PRIMARY_CALLS = 7;

    public function testASecondProcessWaitsOnlyForThePrimaryAndThePassAfterTheCommitIsBounded(): void
    {
        $fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->em->persist($panel);
        $primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->em->persist($primary);
        $additional = [];
        foreach ([2, ...array_map(static fn (int $n): int => $fake->addInbound(['remark' => 'extra'.$n]), range(1, 4))] as $remoteId) {
            $additional[] = $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $remoteId)->setTitle('extra '.$remoteId)->setProtocol('vmess');
            $this->em->persist($inbound);
        }
        $this->em->flush();
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
        $plan = $this->createPlan(150000, $primary)->setAdditionalInbounds($additional);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);

        $fake->script('POST', 'panel/api/clients/add', Fake3xuiPanel::envelope(false, null, 'one inbound is broken'));
        $fake->script('POST', 'panel/api/clients/add', static function (string $m, string $u, array $options) use ($fake): MockResponse {
            $body = json_decode((string) $options['body'], true);
            $fake->clients[$body['client']['email']] = array_merge(['flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'dbId' => 1], $body['client'], ['inboundIds' => [1]]);

            return Fake3xuiPanel::envelope(true);
        });
        $fake->script('POST', null, Fake3xuiPanel::envelope(false, null, 'one inbound is broken'));
        $fake->script('POST', null, Fake3xuiPanel::envelope(true), 10, true);
        $fake->delays = ['(GET|POST) .*' => self::DELAY_MS];

        $other = new Process([(new PhpExecutableFinder())->find(false) ?: PHP_BINARY, dirname(__DIR__, 2).'/Support/bin/lock-order-row.php', (string) $order->getId(), (string) self::LOCK_WAIT_S], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 240);
        $other->start();

        $this->em->clear();
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment');
        $started = microtime(true);
        $outcome = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($payment, 1, null, 'test')->outcome;
        $total = microtime(true) - $started;
        $other->wait();

        self::assertSame(SettlementOutcome::CONFIRMED, $outcome);
        $seen = json_decode(trim($other->getOutput()), true);
        self::assertIsArray($seen, "the other process said nothing.\n".$other->getErrorOutput());
        self::assertTrue($seen['ok'], sprintf('the other process could not take the order row: %s (waited %.1f s)', (string) $seen['error'], (float) $seen['waited']));
        $primaryWork = self::PRIMARY_CALLS * self::DELAY_MS / 1000;
        self::assertLessThan($primaryWork + 6, (float) $seen['waited'], 'it waited for the primary calls only');
        $lines = $fake->requestLines();
        $firstAttach = (int) array_key_first(array_filter($lines, static fn (string $l): bool => str_ends_with($l, '/attach')));
        // The pass after the commit starts with its own read of the client, then attaches.
        self::assertSame(self::PRIMARY_CALLS + 1, $firstAttach, 'no attach call before the commit: '.implode(' | ', $lines));
        self::assertLessThan($primaryWork + ServiceInboundAttacher::AFTER_SALE_BUDGET_SECONDS + 4, $total, 'the pass after the commit ends within its time limit');
        fwrite(STDERR, sprintf("\n[B1] delay %d ms: the other process waited %.1f s for the row; settlement %.1f s in all\n", self::DELAY_MS, $seen['waited'], $total));
    }
}
