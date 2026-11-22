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
 *
 * PM2b: the enabled driver packages (DriverPackageLoader) are merged after the core drivers. A core driver wins: a
 * package whose code a core driver of its module already has is not registered (install refuses the core codes, so
 * only a core driver added later can meet one) and is listed by shadowedPackages(). Two core drivers with one code are
 * still an error.
 */
final class ProviderDriverRegistry
{
    /** @var array<string, array<string, ProviderDriverInterface>>|null module => code => driver */
    private ?array $byModule = null;

    /** @var array<string, array<string, true>> module => code => true, for the drivers that came from a package */
    private array $packageCodes = [];

    /** @var list<string> "module/code" of packages not registered because a core driver has the code */
    private array $shadowed = [];

    /**
     * @param iterable<ProviderDriverInterface> $drivers
     */
    public function __construct(
        private readonly iterable $drivers,
        private readonly ?DriverPackageLoader $packages = null,
    ) {
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

    /** Whether the driver came from an installed package (false for a core driver or an unknown one). */
    public function isPackage(string $module, string $code): bool
    {
        $this->index();

        return isset($this->packageCodes[$module][$code]);
    }

    /** @return list<string> "module/code" of enabled packages a core driver shadows */
    public function shadowedPackages(): array
    {
        $this->index();

        return $this->shadowed;
    }

    /** Read the drivers again on next use (after a package was enabled or disabled in this process). */
    public function reset(): void
    {
        $this->byModule = null;
        $this->packageCodes = [];
        $this->shadowed = [];
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
            $packageCodes = [];
            $shadowed = [];
            foreach (null === $this->packages ? [] : $this->packages->load() as $driver) {
                $module = $driver->module();
                $code = $driver->code();
                if (isset($byModule[$module][$code])) {
                    $shadowed[] = $module.'/'.$code;
                    continue;
                }
                $byModule[$module][$code] = $driver;
                $packageCodes[$module][$code] = true;
            }
            $this->byModule = $byModule;
            $this->packageCodes = $packageCodes;
            $this->shadowed = $shadowed;
        }

        return $this->byModule;
    }
}
