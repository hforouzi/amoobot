<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\Payment;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * F11 (M2c): a panel answer whose client "inboundIds" is not a list (a string, null, a number, an object) never ends
 * in a TypeError. ModernClientsApi::normaliseClient() turns it into a list of ints or drops it ("not listed"), on the
 * single-inbound path as on the multi-inbound one, and the requests the bot sends stay byte-identical to those of a
 * well-formed answer.
 */
final class ModernInboundIdsShapeTest extends IntegrationTestCase
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
    }

    /**
     * @return iterable<string, array{mixed, list<int>|null}>
     */
    public static function shapes(): iterable
    {
        yield 'list' => [[1, '2', 'x', null], [1, 2]];
        yield 'object' => [['a' => 4, 'b' => '5'], [4, 5]];
        yield 'number' => [3, [3]];
        yield 'comma string' => ['1, 2,4', [1, 2, 4]];
        yield 'json string' => ['[4]', [4]];
        yield 'empty string' => ['', []];
        yield 'null' => [null, null];
        yield 'text' => ['all', null];
        yield 'bool' => [true, null];
        yield 'float' => [1.5, null];
    }

    /**
     * @param list<int>|null $expected null: dropped
     */
    #[DataProvider('shapes')]
    public function testNormaliseClientAlwaysGivesAListOfIntsOrNothing(mixed $raw, ?array $expected): void
    {
        $client = ModernClientsApi::normaliseClient(['email' => 'e', 'inboundIds' => $raw]);

        self::assertSame($expected, $client['inboundIds'] ?? null);
        self::assertSame(null !== $expected, array_key_exists('inboundIds', $client));
    }

    public function testASingleInboundSaleSendsTheSameRequestsWhateverTheShape(): void
    {
        $wellFormed = $this->sell([1], false);

        foreach (['1', 1, '[1]', null, 'all', ['x' => 1]] as $shape) {
            self::assertSame($wellFormed, $this->sell($shape, false), 'inboundIds as '.json_encode($shape));
        }
    }

    public function testAMultiInboundSaleNeverThrowsOnAStringOrNull(): void
    {
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
        $wellFormed = $this->sell([1, 2], true);

        self::assertSame($wellFormed, $this->sell('1,2', true));
        self::assertSame(['attached'], $this->lastRows());
        // Not listed: the attach cannot be confirmed, so the row is failed and the scheduled retry takes it (as in M2a).
        $this->sell(null, true);
        self::assertSame(['failed'], $this->lastRows());
    }

    /**
     * Sells a fresh order whose client the panel then reports with $shape as its inboundIds.
     *
     * @return list<string> the requests sent, normalised (UUID, subId, email, expiry)
     */
    private function sell(mixed $shape, bool $multi): array
    {
        $this->em->clear();
        $primary = $this->em->find(VpnInbound::class, $this->primary->getId()) ?? self::fail('inbound');
        $plan = $this->createPlan(150000, $primary);
        if ($multi) {
            $plan->setAdditionalInbounds([$this->em->find(VpnInbound::class, $this->vmess->getId()) ?? self::fail('inbound')]);
            $this->em->flush();
        }
        $user = $this->createUser();
        $order = $this->createOrder($user, $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $email = sprintf('tg_%s_order_%d', $user->getTelegramAccount()?->getTelegramId(), $order->getId());
        $this->fake->requests = [];
        $this->fake->clearScripts();
        $this->fake->script('GET', 'panel/api/clients/get/'.rawurlencode($email), function () use ($email, $shape): MockResponse {
            if (!isset($this->fake->clients[$email])) {
                return Fake3xuiPanel::envelope(false, null, 'client not found');
            }
            $row = array_diff_key($this->fake->clients[$email], ['dbId' => true]);
            $row['inboundIds'] = $shape;

            return Fake3xuiPanel::envelope(true, $row);
        }, 10, true);
        $this->em->clear();

        $outcome = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');

        self::assertSame('confirmed', $outcome->outcome->value, 'inboundIds as '.json_encode($shape));
        $this->lastServiceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service WHERE order_id = ?', [$order->getId()]);

        return array_map(static function (array $r): string {
            $line = $r['method'].' '.$r['path'].' '.json_encode($r['query']).' '.(null === $r['body'] ? '' : json_encode($r['body'], JSON_UNESCAPED_SLASHES));
            $line = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '<uuid>', $line);
            $line = (string) preg_replace('/"subId":"[0-9a-f]+"/', '"subId":"<sub>"', $line);
            $line = (string) preg_replace('/tg_\d+_order_\d+/', '<email>', $line);
            $line = (string) preg_replace('/order #\d+/', 'order #N', $line);
            $line = (string) preg_replace('/"(expiryTime|tgId)":\d+/', '"$1":N', $line);

            return (string) preg_replace('#/sub(Links)?/[0-9a-f]+#', '/sub$1/<sub>', $line);
        }, $this->fake->requests);
    }

    private int $lastServiceId = 0;

    /**
     * @return list<string>
     */
    private function lastRows(): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT status FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$this->lastServiceId]));
    }
}
