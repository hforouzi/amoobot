<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Entity\Order;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Application\VpnProvisioningService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;

/**
 * The application core on a modern panel (container services, fake 3x-ui behind the test HttpClient): an order is
 * provisioned through the modern driver, adoption follows the generated-name rule (a foreign client under a
 * customer-chosen name stops provisioning), and usage sync reads all of a panel's clients in one request.
 */
final class ModernProvisioningIntegrationTest extends IntegrationTestCase
{
    private Fake3xuiPanel $fake;
    private VpnInbound $inbound;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('modern')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $this->inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless')->setFlow('xtls-rprx-vision');
        $this->em->persist($panel);
        $this->em->persist($this->inbound);
        $this->em->flush();
    }

    public function testAnOrderIsProvisionedOnTheModernPanel(): void
    {
        $order = $this->order();
        $service = $this->provisioning()->provisionOrder($order);
        $this->em->flush();

        $email = sprintf('tg_%s_order_%d', $order->getUser()->getTelegramAccount()?->getTelegramId(), $order->getId());
        self::assertArrayHasKey($email, $this->fake->clients);
        $client = $this->fake->clients[$email];
        self::assertSame($this->provisioning()->orderClientUuid($order), $client['id'], 'order-derived UUID');
        self::assertSame(50 * 1073741824, $client['totalGB']);
        self::assertSame([1], $client['inboundIds']);
        self::assertSame($email, $service->getClientEmail());
        self::assertSame($client['subId'], $service->getSubId());
        self::assertNotEmpty($service->getConfigLinks());
        self::assertSame(1, $this->countServicesForOrder($order));
    }

    public function testARetryAdoptsTheClientOfAGeneratedName(): void
    {
        $order = $this->order();
        // Left on the panel by an earlier attempt whose database transaction was lost, with another UUID.
        $email = sprintf('tg_%s_order_%d', $order->getUser()->getTelegramAccount()?->getTelegramId(), $order->getId());
        $this->seedClient($email, '12345678-1234-4234-8234-123456789012');

        $service = $this->provisioning()->provisionOrder($order, [], true);

        self::assertSame('12345678-1234-4234-8234-123456789012', $service->getClientUuid());
        self::assertCount(1, $this->fake->clients, 'adopted, not duplicated');
    }

    public function testAForeignClientUnderACustomerChosenNameStopsProvisioning(): void
    {
        $order = $this->order(['custom' => true, 'finalUsername' => 'alice']);
        $this->seedClient('alice', '87654321-4321-4321-8321-210987654321');
        $before = $this->fake->clients['alice'];

        try {
            $this->provisioning()->provisionOrder($order, [], true);
            self::fail('a foreign client was adopted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('already exists but its UUID does not belong to order', $e->getMessage());
        }
        self::assertSame($before, $this->fake->clients['alice'], 'the foreign client is untouched');
        self::assertNotContains('POST panel/api/clients/add', $this->fake->requestLines());
    }

    public function testUsageSyncReadsAllClientsOfAPanelInOneRequest(): void
    {
        $services = [];
        foreach ([1, 2, 3] as $n) {
            $services[] = $this->provisioning()->provisionOrder($this->order());
        }
        $this->em->flush();
        foreach ($services as $i => $service) {
            $this->fake->useTraffic((string) $service->getClientEmail(), ($i + 1) * 1073741824);
        }
        $this->fake->requests = [];

        $summary = self::getContainer()->get(ServiceUsageSyncService::class)->syncActiveServices();

        self::assertSame(['GET panel/api/clients/list'], $this->fake->requestLines(), 'one request for the whole panel, not one per client');
        self::assertSame(3, $summary->updated);
        $this->em->clear();
        self::assertSame(2 * 1073741824, $this->em->find(VpnService::class, $services[1]->getId())?->getTrafficUsedBytes());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function order(array $metadata = []): Order
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(100000, $this->inbound));
        if ([] !== $metadata) {
            $order->setMetadata($metadata);
            $this->em->flush();
        }

        return $order;
    }

    private function seedClient(string $email, string $uuid): void
    {
        $this->fake->clients[$email] = ['email' => $email, 'id' => $uuid, 'subId' => 'seeded', 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0,
            'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => [1], 'dbId' => 99];
    }

    private function provisioning(): VpnProvisioningService
    {
        return self::getContainer()->get(VpnProvisioningService::class);
    }
}
