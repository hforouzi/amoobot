<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Entity\Plugin;
use App\Payment\Plugin\PluginConfigSchemaValidator;

final class PluginPackageValidator
{
    private const ALLOWED_ROOTS = ['plugin.json', 'src', 'templates', 'translations', 'README.md', 'assets'];

    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly PaymentPluginDoctor $doctor,
        private readonly PluginConfigSchemaValidator $schemaValidator,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{manifest: array<string, mixed>, doctor: ?PluginDoctorResult, errors: list<string>, root: string}
     */
    public function validate(string $zipPath): array
    {
        $tmpDir = $this->createTempDir();
        try {
            $this->extract($zipPath, $tmpDir);
            $root = $this->detectRoot($tmpDir);
            $manifestPath = $root.\DIRECTORY_SEPARATOR.'plugin.json';
            if (!is_file($manifestPath)) {
                return ['manifest' => [], 'doctor' => null, 'errors' => ['plugin.json was not found.'], 'root' => $root];
            }

            $manifest = $this->readManifest($manifestPath);
            $errors = [];
            $manifestValidation = $this->pluginManager->validateManifest($manifest);
            if (!$manifestValidation->valid) {
                array_push($errors, ...$manifestValidation->errors);
            }

            $schemaValidation = $this->schemaValidator->validate($manifest['configSchema'] ?? []);
            if (!$schemaValidation->valid) {
                array_push($errors, ...$schemaValidation->errors);
            }

            $doctor = null;
            if (($manifest['type'] ?? null) === Plugin::TYPE_PAYMENT_GATEWAY) {
                $plugin = (new Plugin())
                    ->setCode((string) ($manifest['code'] ?? ''))
                    ->setType((string) ($manifest['type'] ?? Plugin::TYPE_PAYMENT_GATEWAY))
                    ->setName(is_array($manifest['name'] ?? null) ? $this->stringMap($manifest['name']) : ['en' => (string) ($manifest['code'] ?? '')])
                    ->setVersion((string) ($manifest['version'] ?? ''))
                    ->setPath($root)
                    ->setMainClass((string) ($manifest['mainClass'] ?? ''))
                    ->setManifest($manifest)
                    ->setStatus(Plugin::STATUS_INSTALLED);
                $doctor = $this->doctor->inspect($plugin);
                if (!$doctor->ok()) {
                    array_push($errors, ...$doctor->errors);
                }
            }

            return ['manifest' => $manifest, 'doctor' => $doctor, 'errors' => array_values(array_unique($errors)), 'root' => $root];
        } finally {
            $this->removeDirectory($tmpDir);
        }
    }

    private function createTempDir(): string
    {
        $base = $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'plugin_uploads'.\DIRECTORY_SEPARATOR.'validate';
        if (!is_dir($base) && !@mkdir($base, 0775, true) && !is_dir($base)) {
            throw new PluginInstallException('Could not create package validation workspace.');
        }
        $dir = $base.\DIRECTORY_SEPARATOR.bin2hex(random_bytes(16));
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new PluginInstallException('Could not create package validation workspace.');
        }

        return $dir;
    }

    private function extract(string $zipPath, string $targetDir): void
    {
        if (!is_file($zipPath) || strtolower(pathinfo($zipPath, PATHINFO_EXTENSION)) !== 'zip') {
            throw new PluginInstallException('Only readable .zip plugin packages are accepted.');
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new PluginInstallException('PHP Zip extension is required to validate plugins.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            throw new PluginInstallException('Invalid ZIP file.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = (string) $zip->getNameIndex($i);
                if (!$this->isSafePath($name)) {
                    throw new PluginInstallException('Plugin ZIP contains an unsafe path.');
                }
                if ($this->isIgnoredPath($name)) {
                    continue;
                }
                $normalized = trim(str_replace('\\', '/', $name), '/');
                $root = explode('/', $normalized)[0] ?? '';
                if ('.git' === $root || 'vendor' === $root || 'public' === $root || 'var' === $root || 'cache' === $root) {
                    throw new PluginInstallException('Plugin ZIP contains a disallowed path: '.$name);
                }
            }
            if (!$zip->extractTo($targetDir)) {
                throw new PluginInstallException('Could not extract plugin ZIP.');
            }
        } finally {
            $zip->close();
        }
    }

    private function detectRoot(string $tmpDir): string
    {
        $entries = array_values(array_filter(scandir($tmpDir) ?: [], fn (string $entry): bool => !in_array($entry, ['.', '..', '__MACOSX', '.DS_Store'], true) && !str_starts_with($entry, '._')));
        if (is_file($tmpDir.\DIRECTORY_SEPARATOR.'plugin.json')) {
            return $tmpDir;
        }
        if (1 === count($entries) && is_dir($tmpDir.\DIRECTORY_SEPARATOR.$entries[0])) {
            return $tmpDir.\DIRECTORY_SEPARATOR.$entries[0];
        }

        return $tmpDir;
    }

    private function readManifest(string $path): array
    {
        try {
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PluginInstallException('plugin.json must contain valid JSON.');
        }

        if (!is_array($manifest)) {
            throw new PluginInstallException('plugin.json must contain a JSON object.');
        }

        return $manifest;
    }

    private function isSafePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return !str_contains($normalized, "\0")
            && !str_starts_with($normalized, '/')
            && !preg_match('/^[A-Za-z]:\//', $normalized)
            && !str_contains($normalized, '../')
            && !str_starts_with($normalized, '../')
            && !str_contains($normalized, '/..')
            && $normalized !== '..';
    }

    private function isIgnoredPath(string $path): bool
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');

        return '' === $normalized || str_starts_with($normalized, '__MACOSX/') || '__MACOSX' === $normalized || '.DS_Store' === basename($normalized) || str_starts_with(basename($normalized), '._');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function stringMap(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_scalar($value)) {
                $result[(string) $key] = (string) $value;
            }
        }

        return $result;
    }
}
