<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Admin\Form\ConfigSchemaChoiceNormalizer;
use App\Entity\Plugin;
use App\Entity\VpnPanel;
use App\Payment\Plugin\PluginConfigSchemaValidator;
use App\Plugin\Driver\DriverPluginDoctor;
use App\Plugin\PluginAutoloader;
use App\Plugin\PluginRegistry;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\VpnCapability;
use App\Provisioning\Domain\VpnDriverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

/**
 * The VPN driver contract on the driver package fixture tests/Support/plugins/fake-vpn-driver (PM2b), built the way the
 * registry builds a package: DriverPluginDoctor checks the manifest, core and contract versions and file hashes of the
 * fixture directory, then loads and builds its main class. Its "panel" is in memory, so state is read back.
 */
final class FakeVpnDriverPackageContractTest extends VpnDriverContractTestCase
{
    private VpnDriverInterface $driver;

    protected function setUp(): void
    {
        $root = dirname(__DIR__).'/Support/plugins/fake-vpn-driver';
        $manifest = json_decode((string) file_get_contents($root.'/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        $plugin = (new Plugin())
            ->setCode('fake_vpn')
            ->setType(Plugin::TYPE_VPN_DRIVER)
            ->setVersion((string) $manifest['version'])
            ->setPath($root)
            ->setMainClass((string) $manifest['mainClass'])
            ->setManifest($manifest)
            ->setStatus(Plugin::STATUS_ENABLED);
        $doctor = new DriverPluginDoctor(
            new PluginAutoloader(new PluginRegistry($this->createStub(EntityManagerInterface::class)), dirname(__DIR__, 2)),
            new PluginConfigSchemaValidator(new ConfigSchemaChoiceNormalizer(new NullLogger())),
        );

        $result = $doctor->inspect($plugin);

        self::assertSame([], $result->errors, 'the fixture passes the doctor');
        self::assertInstanceOf(VpnDriverInterface::class, $result->driver);
        $this->driver = $result->driver;
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
        return [VpnCapability::PANEL_SUB_LINKS, VpnCapability::TRAFFIC_RESET];
    }

    public function testItIsAPackageDriverWithSomethingToConnectWith(): void
    {
        self::assertSame('fake_vpn', $this->driver->code());
        self::assertTrue($this->driver->capabilities()->has(VpnCapability::PANEL_SUB_LINKS));
    }
}
