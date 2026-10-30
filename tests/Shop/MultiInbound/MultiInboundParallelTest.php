<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Payment;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Real parallel PHP processes on MariaDB against one fake 3x-ui (shared state file), for a plan with additional
 * inbounds (phase M2a): two settlements of the same payment make exactly one client and one consistent set of rows;
 * two "Retry attach" at once send one attach and count one attempt; so do two scheduled runs at once (phase M2b).
 */
#[Group('subprocess')]
final class MultiInboundParallelTest extends IntegrationTestCase
{
    use SubprocessAssertions;

    private Fake3xuiPanel $fake;
    private VpnInbound $primary;
    private VpnInbound $vmess;
    private VpnInbound $trojan;
    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateFile = sys_get_temp_dir().'/amoobot-multi-inbound-'.bin2hex(random_bytes(4)).'.json';
        $this->fake = new Fake3xuiPanel();
        $trojanId = $this->fake->addInbound(['remark' => 'trojan', 'protocol' => 'trojan', 'port' => 2083]);
        $this->fake->stateFile = $this->stateFile;
        $this->fake->saveState();
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->vmess = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        $this->trojan = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $trojanId)->setTitle('trojan')->setProtocol('trojan');
        foreach ([$panel, $this->primary, $this->vmess, $this->trojan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->getConnection()->insert('setting', ['key_name' => 'plans.multi_inbound_enabled', 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
        @unlink($this->stateFile.'.lock');
        parent::tearDown();
    }

    public function testTwoSettlementsOfOnePaymentMakeOneClientAndOneSetOfRows(): void
    {
        $plan = $this->createPlan(150000, $this->primary)->setAdditionalInbounds([$this->vmess, $this->trojan]);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);

        $outcomes = $this->race('settle', (int) $payment->getId());

        sort($outcomes);
        self::assertSame(['already_processed', 'confirmed'], $outcomes);
        $this->fake->loadState();
        self::assertCount(1, $this->fake->clients, 'exactly one client');
        self::assertSame([1, 2, 4], array_values($this->fake->clients)[0]['inboundIds']);
        self::assertSame(1, count(array_keys($this->fake->requestLines(), 'POST panel/api/clients/add')));
        self::assertSame(1, $this->countServicesForOrder($order));
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT inbound_id, status, attempts FROM vpn_service_inbound ORDER BY inbound_id');
        self::assertSame([
            ['inbound_id' => (string) $this->vmess->getId(), 'status' => 'attached', 'attempts' => '1'],
            ['inbound_id' => (string) $this->trojan->getId(), 'status' => 'attached', 'attempts' => '1'],
        ], array_map(static fn (array $r): array => array_map('strval', $r), $rows));
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
    }

    public function testTwoRetryAttachAtOnceSendOneAttachAndCountOneAttempt(): void
    {
        $serviceId = $this->soldWithBothMissing();

        $outcomes = $this->race('retry-attach', $serviceId);

        sort($outcomes);
        self::assertSame(['done', 'in_progress'], $outcomes);
        $this->assertAttachedOnce($serviceId);
    }

    public function testTwoScheduledRunsAtOnceSendOneAttachAndCountOneAttempt(): void
    {
        $serviceId = $this->soldWithBothMissing();
        $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET next_attempt_at = '2000-01-01 00:00:00'");

        $outcomes = $this->race('attach-scheduled', 0);

        sort($outcomes);
        self::assertSame(['attached=0', 'attached=2'], $outcomes);
        $this->assertAttachedOnce($serviceId);
    }

    private function soldWithBothMissing(): int
    {
        // Sold while inbounds 2 and 4 were missing on the panel; both are back now.
        $plan = $this->createPlan(150000, $this->primary)->setAdditionalInbounds([$this->vmess, $this->trojan]);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $saved = $this->fake->inbounds;
        unset($this->fake->inbounds[2], $this->fake->inbounds[4]);
        $this->fake->saveState();
        $this->em->clear();
        $settled = self::getContainer()->get(\App\Payment\Application\Settlement\PaymentSettlementService::class)
            ->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');
        self::assertSame('confirmed', $settled->outcome->value);
        $serviceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service WHERE order_id = ?', [$order->getId()]);
        self::assertSame(['failed', 'failed'], $this->em->getConnection()->fetchFirstColumn('SELECT status FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$serviceId]));
        $this->fake->loadState();
        $this->fake->inbounds = $saved;
        $this->fake->requests = [];
        $this->fake->saveState();

        return $serviceId;
    }

    private function assertAttachedOnce(int $serviceId): void
    {
        $this->fake->loadState();
        self::assertCount(1, $this->fake->clients);
        self::assertSame([1, 2, 4], array_values($this->fake->clients)[0]['inboundIds']);
        self::assertNotContains('POST panel/api/clients/add', $this->fake->requestLines(), 'a retry never creates a client');
        self::assertCount(1, array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach')), 'one attach call');
        self::assertSame([['attached', '2'], ['attached', '2']], array_map(static fn (array $r): array => [(string) $r['status'], (string) $r['attempts']],
            $this->em->getConnection()->fetchAllAssociative('SELECT status, attempts FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$serviceId])));
    }

    /**
     * @return list<string> the outcome each process printed
     */
    private function race(string $mode, int $id): array
    {
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        foreach ([0, 1] as $n) {
            $processes[$n] = new Process([$php, 'tests/Support/bin/multi-inbound.php', $mode, (string) $id, $this->stateFile, '300'], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 120);
            $processes[$n]->start();
        }
        $outcomes = [];
        foreach ($processes as $n => $process) {
            $process->wait();
            $outcomes[] = self::assertSubprocessClean($process, $mode.' #'.$n);
        }

        return $outcomes;
    }
}
