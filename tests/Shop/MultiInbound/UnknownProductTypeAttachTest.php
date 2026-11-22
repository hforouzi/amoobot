<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Entity\Payment;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PM2a, fail closed: the attach-inbounds job (app:automation:run --only=attach-inbounds, InboundAttachScheduler) skips a
 * service whose product_type has no module: its panel is not read, its rows are not touched, one
 * "unknown_product_type" line is logged. The 'vpn' service of the same batch is retried and attached as before.
 * Fixtures as MultiInboundScheduledAttachTest: two services sold while the vmess inbound was missing on the panel.
 */
final class UnknownProductTypeAttachTest extends IntegrationTestCase
{
    private Fake3xuiPanel $fake;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $trojanId = $this->fake->addInbound(['remark' => 'trojan', 'protocol' => 'trojan', 'port' => 2083]);
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $primary = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $vmess = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('2')->setTitle('ws')->setProtocol('vmess');
        $trojan = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $trojanId)->setTitle('trojan')->setProtocol('trojan');
        foreach ([$panel, $primary, $vmess, $trojan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->setting(MultiInboundSettings::ENABLED, 'true');
        $this->plan = $this->createPlan(150000, $primary)->setAdditionalInbounds([$vmess, $trojan]);
        $this->em->flush();
    }

    public function testTheUnknownTypeIsLeftAloneAndTheVpnServiceIsAttached(): void
    {
        $vmessOnPanel = $this->fake->inbounds[2];
        unset($this->fake->inbounds[2]);
        $vpn = $this->sell();
        $unknown = $this->sell();
        self::assertSame(['failed', 'attached'], $this->statuses($vpn));
        self::assertSame(['failed', 'attached'], $this->statuses($unknown));
        $this->em->getConnection()->executeStatement("UPDATE vpn_service SET product_type = 'x' WHERE id = ?", [$unknown]);
        $this->fake->inbounds[2] = $vmessOnPanel;
        $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET next_attempt_at = '2000-01-01 00:00:00' WHERE status = ?", [VpnServiceInbound::STATUS_FAILED]);
        $before = $this->rows($unknown);
        $unknownEmail = (string) $this->em->getConnection()->fetchOne('SELECT client_email FROM vpn_service WHERE id = ?', [$unknown]);
        $this->fake->requests = [];
        $logger = self::getContainer()->get(RecordingLogger::class);
        $logger->records = [];

        $this->em->clear();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
        $tester->execute(['--only' => 'attach-inbounds']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(['attached', 'attached'], $this->statuses($vpn), 'the vpn service of the same batch is attached');
        self::assertSame($before, $this->rows($unknown), 'not touched: no attempt used, not stamped as checked');
        self::assertNotSame('', $unknownEmail);
        self::assertStringNotContainsString($unknownEmail, implode("\n", $this->requestLog()), 'its client was never asked for');
        self::assertSame([sprintf('unknown_product_type service_id=%d type=x', $unknown)], $this->unknownLines($logger));
    }

    /** @return list<string> read through a method: PHPStan keeps the type of a property a test just assigned */
    private function unknownLines(RecordingLogger $logger): array
    {
        return array_values(array_filter(array_map(static fn (array $r): string => $r['message'], $logger->records), static fn (string $m): bool => str_starts_with($m, 'unknown_product_type')));
    }

    /** @return list<string> read through a method: PHPStan keeps the type of a property a test just assigned */
    private function requestLog(): array
    {
        return $this->fake->requestLines();
    }

    private function sell(): int
    {
        $plan = $this->em->find(Plan::class, $this->plan->getId()) ?? self::fail('plan');
        $order = $this->createOrder($this->createUser(), $plan);
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->em->clear();
        self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->em->find(Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');

        return (int) ($this->em->getRepository(VpnService::class)->findOneBy(['order' => $order->getId()]) ?? self::fail('no service'))->getId();
    }

    /** @return list<string> */
    private function statuses(int $serviceId): array
    {
        return $this->em->getConnection()->fetchFirstColumn('SELECT status FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$serviceId]);
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $serviceId): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$serviceId]);
    }

    private function setting(string $key, string $value): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('DELETE FROM setting WHERE key_name = ?', [$key]);
        $db->insert('setting', ['key_name' => $key, 'value' => $value, 'type' => in_array($value, ['true', 'false'], true) ? 'boolean' : 'integer', 'created_at' => '2026-01-01 00:00:00']);
    }
}
