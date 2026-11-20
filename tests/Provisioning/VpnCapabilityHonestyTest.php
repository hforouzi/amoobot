<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Module\Application\ProviderDriverRegistry;
use App\Module\Domain\CapabilityNotSupported;
use App\Provisioning\Domain\VpnCapability;
use App\Provisioning\Domain\VpnDriverInterface;
use App\Provisioning\Domain\VpnPanelDriverInterface;
use App\Provisioning\Infrastructure\DummyVpnPanelDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Sanaei3xuiModernDriver;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Capability honesty (docs/design/product-modules.md 6.3, PM1a): each production VPN driver, as the container builds
 * it, declares exactly the capabilities of the optional interfaces it implements, and that set is the one the design's
 * table gives for today's code. Using an undeclared capability throws CapabilityNotSupported.
 */
final class VpnCapabilityHonestyTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string<VpnDriverInterface>, string, list<string>}>
     */
    public static function drivers(): iterable
    {
        yield '3x-ui modern' => [Sanaei3xuiModernDriver::class, 'sanaei_3xui', [
            'adopt_existing', 'batch_usage', 'client_owner_note', 'credential_rotation', 'inbound_detach', 'inbound_move',
            'multi_inbound', 'panel_sub_links', 'per_user_inbound_selection', 'resume', 'traffic_add', 'traffic_reset',
        ]];
        // Frozen: "base, adopt_existing, traffic_add only" (6.4).
        yield '3x-ui legacy' => [Sanaei3xuiDriver::class, 'sanaei_3xui_legacy', ['adopt_existing', 'traffic_add', 'traffic_reset']];
        yield 'dummy' => [DummyVpnPanelDriver::class, 'dummy', ['traffic_reset']];
    }

    /**
     * @param class-string<VpnDriverInterface> $class
     * @param list<string>                     $expected
     */
    #[DataProvider('drivers')]
    public function testCapabilitiesEqualTheInstanceofSet(string $class, string $code, array $expected): void
    {
        $driver = self::getContainer()->get($class);
        self::assertInstanceOf(VpnDriverInterface::class, $driver);

        // Read from the class itself, independently of VpnCapability::derive().
        $implemented = class_implements($driver);
        $instanceof = [];
        foreach (VpnCapability::INTERFACES as $capability => $interface) {
            if (isset($implemented[$interface])) {
                $instanceof[] = $capability;
            }
        }
        sort($instanceof);

        self::assertSame($code, $driver->code());
        self::assertSame($instanceof, $driver->capabilities()->names(), 'declared = implemented');
        self::assertSame($expected, $driver->capabilities()->names(), 'as the 6.3 table says for today\'s code');
    }

    /**
     * @param class-string<VpnDriverInterface> $class
     */
    #[DataProvider('drivers')]
    public function testUsingAnUndeclaredCapabilityThrows(string $class): void
    {
        $driver = self::getContainer()->get($class);
        $undeclared = array_diff(VpnCapability::ALL, $driver->capabilities()->names());
        self::assertNotEmpty($undeclared);

        foreach ($undeclared as $capability) {
            try {
                VpnCapability::require($driver, $capability);
                self::fail($capability);
            } catch (CapabilityNotSupported $e) {
                self::assertSame($capability, $e->capability);
            }
        }
    }

    public function testEveryOptionalDriverInterfaceMapsToACapability(): void
    {
        $interfaces = [];
        foreach (glob(dirname(__DIR__, 2).'/src/Provisioning/Domain/*DriverInterface.php') ?: [] as $file) {
            $interfaces[] = 'App\\Provisioning\\Domain\\'.basename($file, '.php');
        }
        $optional = array_values(array_diff($interfaces, [VpnDriverInterface::class, VpnPanelDriverInterface::class]));
        sort($optional);
        $mapped = array_values(array_unique(array_diff(VpnCapability::INTERFACES, [VpnPanelDriverInterface::class])));
        sort($mapped);

        self::assertCount(10, $optional, 'the ten optional interfaces of section 2.3');
        self::assertSame($optional, $mapped, 'a new optional interface needs its capability name');
        self::assertSame([], array_diff(array_keys(VpnCapability::INTERFACES), VpnCapability::ALL));
        self::assertCount(18, VpnCapability::ALL, 'the 18 rows of the 6.3 table');
    }

    public function testTheProviderDriverRegistrySeesExactlyTheProductionVpnDrivers(): void
    {
        $registry = self::getContainer()->get(ProviderDriverRegistry::class);

        self::assertSame(['dummy', 'sanaei_3xui', 'sanaei_3xui_legacy'], $registry->codes('vpn'));
        self::assertInstanceOf(Sanaei3xuiModernDriver::class, $registry->get('vpn', 'sanaei_3xui'));
        self::assertInstanceOf(Sanaei3xuiDriver::class, $registry->get('vpn', 'sanaei_3xui_legacy'));
        self::assertInstanceOf(DummyVpnPanelDriver::class, $registry->get('vpn', 'dummy'));
    }
}
