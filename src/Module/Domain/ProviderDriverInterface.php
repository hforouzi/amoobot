<?php

declare(strict_types=1);

namespace App\Module\Domain;

/**
 * The small base every provider driver shares, whatever its module (docs/design/product-modules.md 2.3). Each module
 * defines its own driver contract on top of it (VpnDriverInterface for 'vpn').
 *
 * PM1a keeps only what today's drivers can answer honestly. connectionSchema(), planSchema() and healthCheck() need
 * the Connection, ConfigSchema and HealthReport types of PM5b and are added there, and capabilities() then takes the
 * connection. Today a driver's capabilities don't depend on the panel: 3x-ui modern and legacy are two drivers.
 */
interface ProviderDriverInterface
{
    /** Stable code, unique within its module: 'sanaei_3xui', 'dummy', ... */
    public function code(): string;

    /** The module this driver serves: 'vpn', later 'server'. */
    public function module(): string;

    public function contractVersion(): int;

    public function capabilities(): CapabilitySet;
}
