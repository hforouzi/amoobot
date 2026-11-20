<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\VpnCapability;
use App\Provisioning\Domain\VpnDriverInterface;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\Fake3xui\Fake3xuiWiring;

/**
 * The VPN driver contract on 3x-ui modern, against the in-process Fake3xuiPanel.
 */
final class Sanaei3xuiModernDriverContractTest extends VpnDriverContractTestCase
{
    use Fake3xuiWiring;

    private VpnPanel $panel;
    private Sanaei3xuiModernDriver $driver;

    protected function setUp(): void
    {
        $this->fake = new Fake3xuiPanel();
        $this->panel = $this->modernPanel();
        $this->driver = $this->modernDriver();
    }

    protected function driver(): VpnDriverInterface
    {
        return $this->driver;
    }

    protected function panel(): VpnPanel
    {
        return $this->panel;
    }

    protected function createRequest(int $order = 42): CreateVpnServiceRequest
    {
        return new CreateVpnServiceRequest(
            username: 'tg_123456_order_'.$order,
            durationDays: 30,
            trafficLimitGb: 50,
            inbound: $this->fakeInbound($this->panel),
            remoteInboundId: '1',
            meta: ['orderId' => $order, 'telegramId' => '123456'],
            clientUuid: sprintf('7f3c9a10-5b2e-4c8d-9e1f-%012d', $order),
        );
    }

    /** Section 6.3, column "3x-ui modern", the rows that have an interface today. */
    protected function expectedCapabilities(): array
    {
        return [
            VpnCapability::ADOPT_EXISTING, VpnCapability::RESUME, VpnCapability::TRAFFIC_ADD, VpnCapability::TRAFFIC_RESET,
            VpnCapability::BATCH_USAGE, VpnCapability::MULTI_INBOUND, VpnCapability::PER_USER_INBOUND_SELECTION,
            VpnCapability::INBOUND_MOVE, VpnCapability::INBOUND_DETACH, VpnCapability::PANEL_SUB_LINKS,
            VpnCapability::CREDENTIAL_ROTATION, VpnCapability::CLIENT_OWNER_NOTE,
        ];
    }

    public function testTheCodeNamesTheModernDriver(): void
    {
        self::assertSame('sanaei_3xui', $this->driver->code());
    }
}
