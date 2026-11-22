<?php

declare(strict_types=1);

namespace App\Plugin\Driver;

use App\Entity\Plugin;
use App\Payment\Plugin\PluginConfigSchemaValidator;
use App\Plugin\VersionConstraint;

/**
 * Manifest version 2, for driver packages (docs/design/product-modules.md 4.3). On top of version 1's fields:
 *
 *   "requires": {"core": ">=2.4 <3.0", "module": "vpn", "contract": {"vpn_driver": 1}},
 *   "capabilities": ["panel_sub_links", ...],   the ceiling of what the driver may declare
 *   "files": {"src/Driver.php": "sha256:<hex>", ...},   every file of the package except plugin.json
 *   "connectionSchema": [...], "planSchema": [...]   optional, in the configSchema format
 *
 * This class checks the shape only. Whether the core version and the contract match, and whether the files match
 * their hashes, is the doctor's job (DriverPluginDoctor): a well-formed package built for another core installs with
 * status 'error' and is never loaded.
 */
final class DriverManifest
{
    public const VERSION = 2;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<string>
     */
    public static function errors(array $manifest, PluginConfigSchemaValidator $schemaValidator): array
    {
        $errors = [];
        $type = (string) ($manifest['type'] ?? '');
        $module = Plugin::DRIVER_TYPES[$type] ?? null;
        if (null === $module) {
            return ['type is not a driver type.'];
        }

        if (($manifest['manifestVersion'] ?? null) !== self::VERSION) {
            $errors[] = 'manifestVersion must be 2 for a driver package.';
        }
        $code = $manifest['code'] ?? null;
        if (is_string($code) && 1 !== preg_match('/^[a-z][a-z0-9_]{0,63}$/', $code)) {
            $errors[] = 'a driver code starts with a lowercase letter and has only lowercase letters, numbers and underscores (at most 64).';
        }

        $requires = $manifest['requires'] ?? null;
        if (!is_array($requires)) {
            $errors[] = 'requires is required (core, module, contract).';
        } else {
            if (!is_string($requires['core'] ?? null) || !VersionConstraint::isValid($requires['core'])) {
                $errors[] = 'requires.core must be a version range such as ">=2.4 <3.0".';
            }
            if (($requires['module'] ?? null) !== $module) {
                $errors[] = sprintf('requires.module must be "%s" for type %s.', $module, $type);
            }
            $contract = $requires['contract'] ?? null;
            if (!is_array($contract) || !is_int($contract[$type] ?? null) || $contract[$type] < 1) {
                $errors[] = sprintf('requires.contract.%s must be a positive integer.', $type);
            }
        }

        $capabilities = $manifest['capabilities'] ?? null;
        if (!is_array($capabilities) || !array_is_list($capabilities)) {
            $errors[] = 'capabilities must be a list of capability names.';
        } else {
            foreach ($capabilities as $capability) {
                if (!is_string($capability) || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $capability)) {
                    $errors[] = 'capabilities must be a list of capability names.';
                    break;
                }
            }
        }

        $files = $manifest['files'] ?? null;
        if (!is_array($files) || [] === $files || array_is_list($files)) {
            $errors[] = 'files must map every file of the package to its "sha256:<hex>" hash.';
        } else {
            foreach ($files as $path => $hash) {
                if (!self::isSafeRelativePath((string) $path) || 'plugin.json' === $path) {
                    $errors[] = sprintf('files: "%s" is not a file path inside the package.', $path);
                } elseif (!is_string($hash) || 1 !== preg_match('/^sha256:[0-9a-f]{64}$/', $hash)) {
                    $errors[] = sprintf('files: the hash of "%s" must be "sha256:" and 64 lowercase hex digits.', $path);
                }
            }
        }

        foreach (['connectionSchema', 'planSchema'] as $key) {
            if (array_key_exists($key, $manifest)) {
                $validation = $schemaValidator->validate($manifest[$key]);
                if (!$validation->valid) {
                    foreach ($validation->errors as $error) {
                        $errors[] = $key.': '.$error;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function contractVersion(array $manifest): ?int
    {
        $type = (string) ($manifest['type'] ?? '');
        $version = $manifest['requires']['contract'][$type] ?? null;

        return is_int($version) ? $version : null;
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<string>
     */
    public static function capabilities(array $manifest): array
    {
        $capabilities = $manifest['capabilities'] ?? [];

        return is_array($capabilities) ? array_values(array_filter($capabilities, 'is_string')) : [];
    }

    private static function isSafeRelativePath(string $path): bool
    {
        return '' !== $path
            && !str_contains($path, "\0")
            && !str_contains($path, '\\')
            && !str_starts_with($path, '/')
            && !in_array('..', explode('/', $path), true)
            && !in_array('', explode('/', $path), true)
            && !in_array('.', explode('/', $path), true);
    }
}
