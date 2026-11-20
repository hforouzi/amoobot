<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\VpnCapability;
use App\Provisioning\Domain\VpnDriverInterface;
use App\Provisioning\Infrastructure\DummyVpnPanelDriver;

/**
 * The VPN driver contract on the development driver 'dummy': no backend, so nothing is read back, and it has only
 * the base operations (traffic_reset).
 */
final class DummyVpnDriverContractTest extends VpnDriverContractTestCase
{
    private DummyVpnPanelDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new DummyVpnPanelDriver();
    }

    protected function driver(): VpnDriverInterface
    {
        return $this->driver;
    }

    protected function panel(): ?VpnPanel
    {
        return null;
    }

    protected function createRequest(int $order = 42): CreateVpnServiceRequest
    {
        return new CreateVpnServiceRequest(username: 'tg_123456_order_'.$order, durationDays: 30, trafficLimitGb: 50);
    }

    protected function expectedCapabilities(): array
    {
        return [VpnCapability::TRAFFIC_RESET];
    }

    protected function readsBackState(): bool
    {
        return false;
    }

    public function testTheCodeIsDummyAndItIsAlsoSelectedForADummyPanel(): void
    {
        self::assertSame('dummy', $this->driver->code());
        self::assertTrue($this->driver->supports((new VpnPanel())->setType('dummy')));
    }
}
