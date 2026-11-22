<?php

declare(strict_types=1);

namespace App\Plugin\Driver;

/**
 * The files of an installed driver package against the manifest's "files" hashes (docs/design/product-modules.md
 * 4.5, item 3): every file except plugin.json must be listed with its sha256, every listed file must exist, and a
 * symbolic link anywhere is refused. Cheap enough to run at install, at enable and every time the registry loads the
 * package.
 */
final class PackageIntegrity
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed> $files relative path => "sha256:<hex>"
     *
     * @return list<string> what does not match; empty when the files are exactly the listed ones
     */
    public static function errors(string $root, array $files): array
    {
        if (!is_dir($root) || is_link($root)) {
            return ['integrity: the package directory is missing.'];
        }

        $found = [];
        $errors = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            if ($item->isLink()) {
                $errors[] = sprintf('integrity: %s is a symbolic link.', $relative);
                continue;
            }
            if ($item->isDir() || 'plugin.json' === $relative) {
                continue;
            }
            $found[$relative] = true;
            $expected = $files[$relative] ?? null;
            if (!is_string($expected)) {
                $errors[] = sprintf('integrity: %s is not listed in the manifest.', $relative);
                continue;
            }
            $actual = hash_file('sha256', $item->getPathname());
            if (false === $actual || !hash_equals($expected, 'sha256:'.$actual)) {
                $errors[] = sprintf('integrity: %s does not match its hash.', $relative);
            }
        }
        foreach (array_keys($files) as $listed) {
            if (!isset($found[(string) $listed]) && 'plugin.json' !== $listed) {
                $errors[] = sprintf('integrity: %s is listed but missing.', $listed);
            }
        }
        sort($errors);

        return $errors;
    }
}
