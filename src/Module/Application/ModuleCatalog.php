<?php

declare(strict_types=1);

namespace App\Module\Application;

use App\Entity\Plugin;
use App\Plugin\CoreVersion;
use App\Plugin\Driver\DriverManifest;
use Doctrine\DBAL\Connection;

/**
 * What app:product:list-modules shows (docs/design/product-modules.md 4.2): every product module and every provider
 * driver, core or package, with its version, contract, status (its plugin row's; a core one without a row is
 * 'enabled', as it runs), whether APP_MODULES_ALLOWED allows its module, and its capabilities.
 *
 * Package drivers that are not loaded (disabled, error, module off) are listed from their rows with the manifest's
 * capabilities. Rows are read with DBAL after the registry has loaded, so a package the loader just set to 'error'
 * shows 'error'.
 */
final class ModuleCatalog
{
    public function __construct(
        private readonly ProductModuleRegistry $modules,
        private readonly ProviderDriverRegistry $drivers,
        private readonly ModuleAllowList $allowList,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<array{kind: string, module: string, code: string, source: string, version: string, contract: string, status: string, allowed: string, capabilities: string}>
     */
    public function rows(): array
    {
        $moduleTypes = $this->modules->types();
        $driverModules = array_values(array_unique([...$moduleTypes, ...array_values(Plugin::DRIVER_TYPES)]));
        $loaded = [];
        foreach ($driverModules as $module) {
            $loaded[$module] = $this->drivers->codes($module);
        }
        $shadowed = $this->drivers->shadowedPackages();

        $plugins = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT code, type, status, source, version, manifest FROM plugin WHERE type IN (?, ?, ?) ORDER BY code',
            [Plugin::TYPE_PRODUCT_MODULE, Plugin::TYPE_VPN_DRIVER, Plugin::TYPE_SERVER_DRIVER],
        ) as $row) {
            $plugins[(string) $row['type']][(string) $row['code']] = $row;
        }

        $rows = [];
        $moduleCodes = array_values(array_unique([...$moduleTypes, ...array_keys($plugins[Plugin::TYPE_PRODUCT_MODULE] ?? [])]));
        sort($moduleCodes);
        foreach ($moduleCodes as $type) {
            $row = $plugins[Plugin::TYPE_PRODUCT_MODULE][$type] ?? null;
            $registered = $this->modules->has($type);
            $rows[] = [
                'kind' => 'module',
                'module' => $type,
                'code' => $type,
                'source' => (string) ($row['source'] ?? Plugin::SOURCE_CORE),
                'version' => $registered ? CoreVersion::CURRENT : '-',
                'contract' => $registered ? (string) $this->modules->get($type)->contractVersion() : '-',
                'status' => null !== $row ? (string) $row['status'] : ($registered ? Plugin::STATUS_ENABLED : '-'),
                'allowed' => $this->allowList->allows($type) ? 'yes' : 'no',
                'capabilities' => '-',
            ];
        }

        foreach ($driverModules as $module) {
            $type = (string) array_search($module, Plugin::DRIVER_TYPES, true);
            $listed = [];
            foreach ($loaded[$module] as $code) {
                $driver = $this->drivers->get($module, $code);
                $package = $this->drivers->isPackage($module, $code);
                $row = $plugins[$type][$code] ?? null;
                $listed[$code] = true;
                $rows[] = [
                    'kind' => 'driver',
                    'module' => $module,
                    'code' => $code,
                    'source' => $package ? Plugin::SOURCE_PACKAGE : Plugin::SOURCE_CORE,
                    'version' => $package ? (string) ($row['version'] ?? '-') : CoreVersion::CURRENT,
                    'contract' => (string) $driver->contractVersion(),
                    'status' => null !== $row ? (string) $row['status'] : Plugin::STATUS_ENABLED,
                    'allowed' => $this->allowList->allows($module) ? 'yes' : 'no',
                    'capabilities' => self::names($driver->capabilities()->names()),
                ];
            }
            foreach ($plugins[$type] ?? [] as $code => $row) {
                if (isset($listed[$code]) || Plugin::SOURCE_PACKAGE !== $row['source']) {
                    continue;
                }
                $manifest = json_decode((string) $row['manifest'], true);
                $manifest = is_array($manifest) ? $manifest : [];
                $contract = DriverManifest::contractVersion($manifest);
                $rows[] = [
                    'kind' => 'driver',
                    'module' => $module,
                    'code' => (string) $code,
                    'source' => Plugin::SOURCE_PACKAGE,
                    'version' => (string) $row['version'],
                    'contract' => null === $contract ? '-' : (string) $contract,
                    'status' => in_array($module.'/'.$code, $shadowed, true) ? 'shadowed' : (string) $row['status'],
                    'allowed' => $this->allowList->allows($module) ? 'yes' : 'no',
                    'capabilities' => self::names(DriverManifest::capabilities($manifest)).' (manifest)',
                ];
            }
        }

        return $rows;
    }

    /** @param list<string> $names */
    private static function names(array $names): string
    {
        return [] === $names ? '-' : implode(', ', $names);
    }
}
