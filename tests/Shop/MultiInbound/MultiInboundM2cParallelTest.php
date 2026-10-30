<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\InboundAttachScheduler;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * M2c's changes that touch locks, in real parallel PHP processes on MariaDB against one fake 3x-ui (shared state file,
 * every panel request delayed so the processes overlap):
 * - F2: two settlements of one payment whose read after the attach fails: one provisions (rows pending), the other
 *   finds it processed; then a scheduled run and a "Retry attach" at once settle the pending rows without a second
 *   attach;
 * - F5: the same with the inline budget used up: the second settlement waits only for the bounded work;
 * - F4: an admin edits the plan while its order is being provisioned: the order gets its snapshot either way;
 * - F7: two scheduled runs at once count one re-attach cycle per removal, and stop a loop with one alert.
 */
#[Group('subprocess')]
final class MultiInboundM2cParallelTest extends IntegrationTestCase
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
        $this->stateFile = sys_get_temp_dir().'/amoobot-m2c-'.bin2hex(random_bytes(4)).'.json';
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
        $this->em->getConnection()->insert('setting', ['key_name' => MultiInboundSettings::ENABLED, 'value' => '1', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
        @unlink($this->stateFile.'.lock');
        parent::tearDown();
    }

    public function testF2TwoSettlementsWithAFailingReadThenTheScheduledRunAndARetryAtOnce(): void
    {
        [$payment, $plan] = $this->order();

        $outcomes = $this->race([['settle', (int) $payment->getId(), 'read-blip'], ['settle', (int) $payment->getId(), 'read-blip']]);

        sort($outcomes);
        self::assertSame(['already_processed', 'confirmed'], $outcomes);
        $this->fake->loadState();
        self::assertCount(1, $this->fake->clients);
        self::assertSame([1, 2, 4], array_values($this->fake->clients)[0]['inboundIds'], 'the attach landed');
        $serviceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service WHERE order_id = (SELECT id FROM `order` WHERE plan_id = ?)', [$plan->getId()]);
        self::assertSame([['pending', '1'], ['pending', '1']], $this->rows($serviceId));

        $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET updated_at = '2000-01-01 00:00:00'");
        $this->fake->requests = [];
        $this->fake->saveState();
        $this->race([['attach-scheduled', 0, ''], ['retry-attach', $serviceId, '']]);

        $this->fake->loadState();
        self::assertSame(['attached', 'attached'], array_column($this->rows($serviceId), 0));
        self::assertLessThanOrEqual(1, count(array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach'))), 'at most one attach call between them');
        self::assertNotContains('POST panel/api/clients/add', $this->fake->requestLines());
    }

    public function testF5TwoSettlementsWithTheBudgetUsedUpTheSecondWaitsOnlyForTheBoundedWork(): void
    {
        [$payment, $plan] = $this->order();

        $processes = $this->raceProcesses([['settle', (int) $payment->getId(), 'budget'], ['settle', (int) $payment->getId(), 'budget']]);

        $outcomes = array_map(static fn (Process $p): string => trim($p->getOutput()), $processes);
        sort($outcomes);
        self::assertSame(['already_processed', 'confirmed'], $outcomes);
        $this->fake->loadState();
        self::assertCount(2, array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach')), 'the refused batch and one single attach; the other is left');
        $serviceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service WHERE order_id = (SELECT id FROM `order` WHERE plan_id = ?)', [$plan->getId()]);
        self::assertSame([
            ['pending', '1', InboundAttachDriverInterface::NOT_CONFIRMED],
            ['pending', '0', InboundAttachDriverInterface::NOT_TRIED],
        ], array_map(static fn (array $r): array => [(string) $r['status'], (string) $r['attempts'], $r['last_error']],
            $this->em->getConnection()->fetchAllAssociative('SELECT status, attempts, last_error FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$serviceId])));
        foreach ($processes as $process) {
            self::assertMatchesRegularExpression('/elapsed=\d+\.\d+/', $process->getErrorOutput());
            self::assertLessThan(15.0, (float) preg_replace('/.*elapsed=(\d+\.\d+).*/s', '$1', $process->getErrorOutput()), 'nobody waited behind the lock for long');
        }
    }

    public function testF4AnAdminEditsThePlanWhileItsOrderIsProvisionedTheSnapshotRules(): void
    {
        [$payment, $plan] = $this->order();

        $outcomes = $this->race([['settle', (int) $payment->getId(), ''], ['edit-plan', (int) $plan->getId(), '']], 150);

        self::assertContains('confirmed', $outcomes);
        self::assertContains('edited', $outcomes);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan_additional_inbound WHERE plan_id = ?', [$plan->getId()]), 'the edit was saved');
        $this->fake->loadState();
        self::assertSame([1, 2, 4], array_values($this->fake->clients)[0]['inboundIds'], 'the order got what it was sold with');
        $serviceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service WHERE order_id = (SELECT id FROM `order` WHERE plan_id = ?)', [$plan->getId()]);
        self::assertSame([['attached', '1'], ['attached', '1']], $this->rows($serviceId));
    }

    public function testF7TwoScheduledRunsAtOnceCountOneCycleAndStopALoopWithOneAlert(): void
    {
        $serviceId = $this->soldAllAttached();
        $db = $this->em->getConnection();

        // One removal: both runs see it, one cycle is counted, one attach call.
        $this->removeTrojanOnPanel();
        $db->executeStatement("UPDATE vpn_service_inbound SET checked_at = '2000-01-01 00:00:00'");
        $this->race([['attach-run', 0, ''], ['attach-run', 0, '']]);
        $this->fake->loadState();
        self::assertSame(1, (int) $db->fetchOne('SELECT reattach_count FROM vpn_service_inbound WHERE inbound_id = ?', [$this->trojan->getId()]));
        self::assertCount(1, array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach')));
        self::assertSame([1, 2, 4], array_values($this->fake->clients)[0]['inboundIds']);

        // At the limit: the next removal stops it, in one of the two runs only, with one alert.
        $db->executeStatement('UPDATE vpn_service_inbound SET reattach_count = ? WHERE inbound_id = ?', [InboundAttachScheduler::MAX_REATTACH_CYCLES, $this->trojan->getId()]);
        $this->removeTrojanOnPanel();
        $db->executeStatement("UPDATE vpn_service_inbound SET checked_at = '2000-01-01 00:00:00'");
        $outputs = $this->race([['attach-run', 0, ''], ['attach-run', 0, '']]);

        $this->fake->loadState();
        self::assertSame([], array_filter($this->fake->requestLines(), static fn (string $l): bool => str_ends_with($l, '/attach')), 'not attached again');
        $row = $db->fetchAssociative('SELECT status, attempts, last_error, alert_state, reattach_count FROM vpn_service_inbound WHERE inbound_id = ?', [$this->trojan->getId()]) ?: [];
        self::assertSame(['failed', (string) MultiInboundSettings::MAX_MAX_ATTEMPTS, InboundAttachScheduler::REATTACH_STOPPED, 'exhausted', (string) (InboundAttachScheduler::MAX_REATTACH_CYCLES + 1)], array_map('strval', array_values($row)));
        $alerts = array_sum(array_map(static fn (string $o): int => (int) preg_replace('/.*alerts=(\d+).*/', '$1', $o), $outputs));
        self::assertSame(1, $alerts, 'one alert between the two runs: '.implode(' | ', $outputs));
    }

    /**
     * @return array{Payment, Plan}
     */
    private function order(): array
    {
        $plan = $this->createPlan(150000, $this->primary)->setAdditionalInbounds([$this->vmess, $this->trojan]);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $plan);

        return [$this->createPayment($order, null, PaymentStatus::SUBMITTED), $plan];
    }

    private function soldAllAttached(): int
    {
        [$payment, $plan] = $this->order();
        $this->em->clear();
        $settled = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');
        self::assertSame('confirmed', $settled->outcome->value);
        $serviceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service WHERE order_id = (SELECT id FROM `order` WHERE plan_id = ?)', [$plan->getId()]);
        self::assertSame([['attached', '1'], ['attached', '1']], $this->rows($serviceId));
        // Its first-failure alerts are out of the way (none) and the admins' chat exists (.env.test).
        return $serviceId;
    }

    private function removeTrojanOnPanel(): void
    {
        $this->fake->loadState();
        $key = (string) array_key_first($this->fake->clients);
        $this->fake->clients[$key]['inboundIds'] = [1, 2];
        $this->fake->requests = [];
        $this->fake->saveState();
    }

    /**
     * @return list<array{string, string}> status, attempts
     */
    private function rows(int $serviceId): array
    {
        return array_map(static fn (array $r): array => [(string) $r['status'], (string) $r['attempts']],
            $this->em->getConnection()->fetchAllAssociative('SELECT status, attempts FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$serviceId]));
    }

    /**
     * @param list<array{string, int, string}> $steps mode, id, scenario
     *
     * @return list<string> what each process printed
     */
    private function race(array $steps, int $delayMs = 300): array
    {
        $outputs = [];
        foreach ($this->raceProcesses($steps, $delayMs) as $n => $process) {
            $outputs[] = self::assertSubprocessClean($process, $steps[$n][0].' #'.$n);
        }

        return $outputs;
    }

    /**
     * @param list<array{string, int, string}> $steps
     *
     * @return list<Process>
     */
    private function raceProcesses(array $steps, int $delayMs = 300): array
    {
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        foreach ($steps as $n => [$mode, $id, $scenario]) {
            $processes[$n] = new Process([$php, 'tests/Support/bin/multi-inbound.php', $mode, (string) $id, $this->stateFile, (string) $delayMs, $scenario], dirname(__DIR__, 3), ['APP_ENV' => 'test'], null, 120);
            $processes[$n]->start();
        }
        foreach ($processes as $n => $process) {
            $process->wait();
            self::assertSubprocessClean($process, $steps[$n][0].' #'.$n);
        }

        return array_values($processes);
    }
}
