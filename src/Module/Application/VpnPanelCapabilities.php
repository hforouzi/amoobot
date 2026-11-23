<?php

declare(strict_types=1);

namespace App\Module\Application;

use App\Entity\VpnPanel;
use App\Module\Domain\CapabilitySet;
use App\Provisioning\Domain\VpnCapability;
use App\Provisioning\Domain\VpnDriverInterface;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;

/** Read-only capability lookup; resolving a known core panel never loads driver packages. */
final class VpnPanelCapabilities
{
    public function __construct(private readonly VpnPanelDriverRegistry $drivers)
    {
    }

    public function forPanel(?VpnPanel $panel): CapabilitySet
    {
        if (null === $panel) {
            return CapabilitySet::none();
        }
        try {
            $driver = $this->drivers->resolve($panel);
        } catch (\RuntimeException) {
            return CapabilitySet::none();
        }

        return $driver instanceof VpnDriverInterface ? $driver->capabilities() : CapabilitySet::none();
    }

    public function has(?VpnPanel $panel, string $capability): bool
    {
        return $this->forPanel($panel)->has($capability);
    }

    /** Frozen legacy rendering is the explicit exception to panel_sub_links (design 6.3). */
    public function usesLegacyConfig(?VpnPanel $panel): bool
    {
        if (null === $panel) {
            return false;
        }
        try {
            $driver = $this->drivers->resolve($panel);
        } catch (\RuntimeException) {
            return false;
        }

        return $driver instanceof VpnDriverInterface && 'sanaei_3xui_legacy' === $driver->code();
    }
}
