<?php

declare(strict_types=1);

namespace App\Tests\Module;

use PHPUnit\Framework\TestCase;

/**
 * Only the module code itself (src/Module/) and the service wiring in config/ may name the module registries. Since
 * PM1c every other caller asks ProductModuleResolver (src/Module/Application) for an order's or a service's module.
 */
final class ModuleRegistryUsageGuardTest extends TestCase
{
    private const REGISTRIES = ['ProductModuleRegistry', 'ProviderDriverRegistry'];

    /** Paths (from the project root) allowed to name a registry: a directory ends with '/', anything else is one file. */
    private const ALLOWED = ['src/Module/'];

    public function testNoProductionClassUsesTheRegistriesYet(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $scanned = 0;
        foreach (['src', 'templates', 'bin'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                ++$scanned;
                foreach (self::ALLOWED as $allowed) {
                    if (str_ends_with($allowed, '/') ? str_starts_with($relative, $allowed) : $relative === $allowed) {
                        continue 2;
                    }
                }
                $content = (string) file_get_contents($file->getPathname());
                foreach (self::REGISTRIES as $registry) {
                    if (1 === preg_match('/\b'.$registry.'\b/', $content)) {
                        $offenders[] = $relative.' names '.$registry;
                    }
                }
            }
        }

        self::assertGreaterThan(500, $scanned, 'the scan saw the source tree');
        self::assertSame([], $offenders);
    }

    public function testTheGuardWouldSeeAUse(): void
    {
        self::assertSame(1, preg_match('/\bProductModuleRegistry\b/', 'public function __construct(private ProductModuleRegistry $modules)'));
        self::assertSame(0, preg_match('/\bProductModuleRegistry\b/', 'MyProductModuleRegistryLike'));
    }
}
