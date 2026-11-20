<?php

declare(strict_types=1);

namespace App\Module\Application;

use App\Module\Domain\ModuleRegistryException;
use App\Module\Domain\ProviderDriverInterface;

/**
 * The provider drivers by module and driver code, with no ordering (docs/design/product-modules.md 2.1, 2.3), from the
 * 'app.provider_driver' tag. Unlike VpnPanelDriverRegistry::resolve() it never asks supports(): a code names one driver.
 * driverFor(Connection) comes with the Connection type in PM5b.
 * PM1a: no production class uses it yet (guarded by ModuleRegistryUsageGuardTest).
 */
final class ProviderDriverRegistry
{
    /** @var array<string, array<string, ProviderDriverInterface>>|null module => code => driver */
    private ?array $byModule = null;

    /**
     * @param iterable<ProviderDriverInterface> $drivers
     */
    public function __construct(private readonly iterable $drivers)
    {
    }

    /**
     * @throws ModuleRegistryException unknown module or code, or a code registered twice in a module
     */
    public function get(string $module, string $code): ProviderDriverInterface
    {
        return $this->index()[$module][$code] ?? throw ModuleRegistryException::unknownDriver($module, $code);
    }

    public function has(string $module, string $code): bool
    {
        return isset($this->index()[$module][$code]);
    }

    /** @return list<string> the driver codes of a module, sorted */
    public function codes(string $module): array
    {
        $codes = array_map('strval', array_keys($this->index()[$module] ?? []));
        sort($codes);

        return $codes;
    }

    /**
     * @return array<string, array<string, ProviderDriverInterface>>
     */
    private function index(): array
    {
        if (null === $this->byModule) {
            $byModule = [];
            foreach ($this->drivers as $driver) {
                $module = $driver->module();
                $code = $driver->code();
                if (isset($byModule[$module][$code])) {
                    throw ModuleRegistryException::duplicateDriver($module, $code);
                }
                $byModule[$module][$code] = $driver;
            }
            $this->byModule = $byModule;
        }

        return $this->byModule;
    }
}
