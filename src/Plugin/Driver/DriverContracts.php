<?php

declare(strict_types=1);

namespace App\Plugin\Driver;

use App\Entity\Plugin;
use App\Provisioning\Domain\VpnCapability;
use App\Provisioning\Domain\VpnDriverInterface;

/**
 * The driver contract each driver plugin type is checked against (docs/design/product-modules.md 4.3, 6.3): the
 * interface its main class implements, the contract version the core speaks, the capability names it may declare and
 * the capabilities of which a package must declare at least one.
 *
 * 'server_driver' has no contract in this core yet (ServerDriverInterface is PM6): a server driver package is
 * refused until then.
 */
final class DriverContracts
{
    private function __construct()
    {
    }

    /**
     * @return array{interface: class-string, version: int, capabilities: list<string>, oneOf: list<string>}|null
     */
    public static function forType(string $type): ?array
    {
        return match ($type) {
            Plugin::TYPE_VPN_DRIVER => [
                'interface' => VpnDriverInterface::class,
                'version' => VpnDriverInterface::VERSION,
                'capabilities' => VpnCapability::ALL,
                // 6.3: a new VPN driver must give the customer something to connect with. Enforced for packages only:
                // the core driver 'dummy' has neither and keeps working.
                'oneOf' => [VpnCapability::PANEL_SUB_LINKS, VpnCapability::SUBSCRIPTION_URL],
            ],
            default => null,
        };
    }

    /**
     * The capabilities a loaded driver really has, for the honesty check: for VPN, exactly its instanceof set
     * (VpnCapability::derive()).
     *
     * @return list<string>|null null when the type has no contract
     */
    public static function derivedCapabilities(string $type, object $driver): ?array
    {
        return match ($type) {
            Plugin::TYPE_VPN_DRIVER => VpnCapability::derive($driver)->names(),
            default => null,
        };
    }
}
