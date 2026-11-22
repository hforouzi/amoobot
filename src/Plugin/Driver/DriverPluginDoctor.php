<?php

declare(strict_types=1);

namespace App\Plugin\Driver;

use App\Entity\Plugin;
use App\Module\Domain\ProviderDriverInterface;
use App\Payment\Plugin\PluginConfigSchemaValidator;
use App\Plugin\CoreVersion;
use App\Plugin\PluginAutoloader;
use App\Plugin\VersionConstraint;

/**
 * Whether a driver package (vpn_driver, server_driver) may be loaded (docs/design/product-modules.md 4.2-4.5). Run at
 * install, at enable, by app:plugin:doctor and every time the driver registry loads the package.
 *
 * Before any of the package's code is loaded: the manifest (version 2), plugin.json on disk equal to the installed
 * manifest, requires.core against CoreVersion::CURRENT, requires.contract against the contract the core speaks for its
 * type, and every file against its sha256. Only then: the main class implements the type's interface, is built with no
 * constructor arguments, answers the manifest's code, the module and the core's contract version; its capabilities are
 * exactly its instanceof set, within the manifest's list, and (VPN) include panel_sub_links or subscription_url.
 */
final class DriverPluginDoctor
{
    public function __construct(
        private readonly PluginAutoloader $autoloader,
        private readonly PluginConfigSchemaValidator $schemaValidator,
        private readonly string $coreVersion = CoreVersion::CURRENT,
    ) {
    }

    public function inspect(?Plugin $plugin): DriverDoctorResult
    {
        if (!$plugin instanceof Plugin) {
            return new DriverDoctorResult(false, null, null, null, '', '', '', '', $this->coreVersion, false, null, null, false, false, false, '', false, [], [], ['plugin_missing'], null);
        }

        $manifest = $plugin->getManifest();
        $type = $plugin->getType();
        $module = Plugin::DRIVER_TYPES[$type] ?? null;
        $contract = DriverContracts::forType($type);
        $mainClass = trim((string) $plugin->getMainClass());
        $srcDir = $this->autoloader->sourcePathFor($plugin);
        $root = dirname($srcDir);
        $coreRange = is_string($manifest['requires']['core'] ?? null) ? $manifest['requires']['core'] : '';
        $contractRequired = DriverManifest::contractVersion($manifest);
        $manifestCapabilities = DriverManifest::capabilities($manifest);
        $errors = [];

        if (null === $module) {
            $errors[] = 'plugin_type_invalid';
        }
        if ($plugin->isCore()) {
            $errors[] = 'a core row has no package to inspect';
        }
        if (1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $plugin->getCode())) {
            $errors[] = 'plugin_code_invalid';
        }
        if (null !== $module) {
            array_push($errors, ...DriverManifest::errors($manifest, $this->schemaValidator));
        }
        if (($manifest['code'] ?? null) !== $plugin->getCode()) {
            $errors[] = 'manifest code does not match the plugin code.';
        }
        if ('' === $mainClass || $mainClass !== trim((string) ($manifest['mainClass'] ?? ''))) {
            $errors[] = 'plugin mainClass does not match manifest mainClass.';
        }
        $onDisk = $this->readManifest($root.\DIRECTORY_SEPARATOR.'plugin.json');
        if (null === $onDisk) {
            $errors[] = 'plugin.json missing.';
        } elseif ($onDisk != $manifest) {
            $errors[] = 'plugin.json on disk differs from the installed manifest.';
        }

        $coreOk = '' !== $coreRange && VersionConstraint::satisfies($this->coreVersion, $coreRange);
        if ('' !== $coreRange && !$coreOk) {
            $errors[] = sprintf('core_version: the package requires core "%s", this core is %s.', $coreRange, $this->coreVersion);
        }

        $contractCore = $contract['version'] ?? null;
        $contractOk = null !== $contractCore && $contractRequired === $contractCore;
        if (null !== $module && null === $contractCore) {
            $errors[] = sprintf('contract: this core has no %s contract yet.', $type);
        } elseif (null !== $contractRequired && !$contractOk) {
            $errors[] = sprintf('contract: the package is built for %s contract %d, this core speaks %d.', $type, $contractRequired, (int) $contractCore);
        }

        $integrityErrors = is_array($manifest['files'] ?? null) ? PackageIntegrity::errors($root, $manifest['files']) : ['integrity: no file list.'];
        array_push($errors, ...$integrityErrors);
        $integrityOk = [] === $integrityErrors;

        $expectedInterface = $contract['interface'] ?? '';
        $classExists = false;
        $implements = false;
        $capabilities = [];
        $driver = null;
        // No code of the package runs unless everything above holds.
        if ([] === $errors && null !== $contract) {
            $this->autoloader->register($plugin);
            $classExists = class_exists($mainClass);
            $implements = $classExists && is_subclass_of($mainClass, $expectedInterface);
            if (!$classExists) {
                $errors[] = 'class_not_found';
            } elseif (!$implements) {
                $errors[] = 'interface_not_implemented';
            } else {
                [$driver, $buildErrors] = $this->build($mainClass);
                array_push($errors, ...$buildErrors);
            }
            if ($driver instanceof ProviderDriverInterface) {
                $capabilities = $driver->capabilities()->names();
                array_push($errors, ...$this->driverErrors($driver, $plugin, (string) $module, $contract, $manifestCapabilities, $capabilities));
            }
        }

        return new DriverDoctorResult(
            true,
            $plugin->getStatus(),
            $type,
            $module,
            $plugin->getPath(),
            $mainClass,
            $srcDir,
            $coreRange,
            $this->coreVersion,
            $coreOk,
            $contractRequired,
            $contractCore,
            $contractOk,
            $integrityOk,
            $classExists,
            $expectedInterface,
            $implements,
            $manifestCapabilities,
            $capabilities,
            array_values(array_unique($errors)),
            [] === $errors ? $driver : null,
        );
    }

    /**
     * @param class-string $mainClass
     *
     * @return array{?ProviderDriverInterface, list<string>}
     */
    private function build(string $mainClass): array
    {
        try {
            $constructor = (new \ReflectionClass($mainClass))->getConstructor();
            if (null !== $constructor && $constructor->getNumberOfRequiredParameters() > 0) {
                return [null, ['the main class must be built with no constructor arguments.']];
            }
            $driver = new $mainClass();
        } catch (\Throwable $exception) {
            return [null, ['the main class could not be built: '.$exception::class]];
        }

        return $driver instanceof ProviderDriverInterface ? [$driver, []] : [null, ['interface_not_implemented']];
    }

    /**
     * @param array{interface: class-string, version: int, capabilities: list<string>, oneOf: list<string>} $contract
     * @param list<string>                                                                                     $manifestCapabilities
     * @param list<string>                                                                                     $capabilities
     *
     * @return list<string>
     */
    private function driverErrors(ProviderDriverInterface $driver, Plugin $plugin, string $module, array $contract, array $manifestCapabilities, array $capabilities): array
    {
        $errors = [];
        try {
            if ($driver->code() !== $plugin->getCode()) {
                $errors[] = sprintf('the driver\'s code() "%s" is not the plugin code.', $driver->code());
            }
            if ($driver->module() !== $module) {
                $errors[] = sprintf('the driver\'s module() "%s" is not "%s".', $driver->module(), $module);
            }
            if ($driver->contractVersion() !== $contract['version']) {
                $errors[] = sprintf('contract: the driver\'s contractVersion() is %d, this core speaks %d.', $driver->contractVersion(), $contract['version']);
            }
        } catch (\Throwable $exception) {
            return ['the driver\'s identity could not be read: '.$exception::class];
        }

        $unknown = array_values(array_diff($manifestCapabilities, $contract['capabilities']));
        if ([] !== $unknown) {
            $errors[] = 'capabilities: unknown name(s) in the manifest: '.implode(', ', $unknown).'.';
        }
        $beyond = array_values(array_diff($capabilities, $manifestCapabilities));
        if ([] !== $beyond) {
            $errors[] = 'capabilities: the driver declares more than its manifest lists: '.implode(', ', $beyond).'.';
        }
        $derived = DriverContracts::derivedCapabilities((string) $plugin->getType(), $driver) ?? [];
        if ($derived !== $capabilities) {
            $errors[] = 'capabilities: the declared set is not exactly the implemented interfaces.';
        }
        if ([] === array_intersect($contract['oneOf'], $capabilities)) {
            $errors[] = sprintf('capabilities: a %s driver package must declare one of %s.', $module, implode(', ', $contract['oneOf']));
        }

        return $errors;
    }

    /**
     * @return array<mixed>|null
     */
    private function readManifest(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
