<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Entity\Plugin;
use App\Payment\Plugin\PluginConfigSchemaValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PluginManager
{
    private const MAX_UPLOAD_BYTES = 10_485_760;
    private const ALLOWED_ROOTS = [
        'plugin.json',
        'src',
        'templates',
        'translations',
        'README.md',
        'assets',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly PaymentPluginDoctor $doctor,
        private readonly PluginConfigSchemaValidator $schemaValidator,
        private readonly string $projectDir,
        private readonly int $maxUploadBytes = self::MAX_UPLOAD_BYTES,
    ) {
    }

    public function installFromZip(UploadedFile|string $zipPath): PluginInstallResult
    {
        $sourcePath = $zipPath instanceof UploadedFile ? $zipPath->getPathname() : $zipPath;
        $tmpDir = null;
        $cleanupDir = null;

        try {
            $this->validateZipSource($zipPath);
            $tmpDir = $this->createTempDir();
            $cleanupDir = $tmpDir;
            $this->extractZipSafely($sourcePath, $tmpDir);
            $tmpDir = $this->normalizeExtractedRoot($tmpDir);

            $manifestPath = $tmpDir.\DIRECTORY_SEPARATOR.'plugin.json';
            if (!is_file($manifestPath)) {
                throw new PluginInstallException('plugin.json was not found in the plugin ZIP root.');
            }

            $manifest = $this->readManifest($manifestPath);
            $validation = $this->validateManifest($manifest);
            if (!$validation->valid) {
                throw new PluginInstallException($validation->message());
            }
            $schemaValidation = $this->schemaValidator->validate($manifest['configSchema'] ?? []);
            if (!$schemaValidation->valid) {
                throw new PluginInstallException($schemaValidation->message());
            }

            $code = (string) $manifest['code'];
            if (null !== $this->entityManager->getRepository(Plugin::class)->findOneBy(['code' => $code])) {
                throw new PluginInstallException('Plugin already installed.');
            }

            $targetDir = $this->pluginDir($code);
            if (file_exists($targetDir)) {
                throw new PluginInstallException('Plugin already installed.');
            }

            $this->ensureDirectory(dirname($targetDir));
            if (!rename($tmpDir, $targetDir)) {
                throw new PluginInstallException('Could not move plugin files into storage.');
            }
            $tmpDir = null;

            $now = new \DateTimeImmutable();
            $plugin = (new Plugin())
                ->setCode($code)
                ->setType((string) $manifest['type'])
                ->setName($this->stringMap((array) $manifest['name']))
                ->setVersion((string) $manifest['version'])
                ->setDescription(isset($manifest['description']) && is_array($manifest['description']) ? $this->stringMap($manifest['description']) : null)
                ->setStatus(Plugin::STATUS_INSTALLED)
                ->setPath($this->relativeProjectPath($targetDir))
                ->setMainClass((string) $manifest['mainClass'])
                ->setManifest($manifest)
                ->setPermissions($this->permissionsFromManifest($manifest))
                ->setInstalledAt($now)
                ->setCreatedAt($now)
                ->setUpdatedAt($now);

            if (Plugin::TYPE_PAYMENT_GATEWAY === $plugin->getType()) {
                $doctor = $this->doctor->inspect($plugin);
                if (!$doctor->ok()) {
                    $plugin
                        ->setStatus(Plugin::STATUS_ERROR)
                        ->setErrorMessage($doctor->errorMessage());
                }
            }

            $this->entityManager->persist($plugin);
            $this->entityManager->flush();
            if (null !== $cleanupDir && is_dir($cleanupDir)) {
                $this->removeDirectory($cleanupDir);
            }

            return PluginInstallResult::success($plugin);
        } catch (PluginInstallException $exception) {
            if (null !== $cleanupDir) {
                $this->removeDirectory($cleanupDir);
            }
            $this->logger->warning('Plugin installation rejected: '.$exception->getMessage());

            return PluginInstallResult::failure($exception->getMessage());
        } catch (\Throwable $exception) {
            if (null !== $cleanupDir) {
                $this->removeDirectory($cleanupDir);
            }
            $this->logger->error('Plugin installation failed: '.$exception->getMessage());

            return PluginInstallResult::failure('Plugin installation failed.');
        }
    }

    public function validateManifest(array $manifest): ValidationResult
    {
        $errors = [];

        if (($manifest['manifestVersion'] ?? null) !== 1) {
            $errors[] = 'manifestVersion must be 1.';
        }

        $code = $manifest['code'] ?? null;
        if (!is_string($code) || '' === trim($code)) {
            $errors[] = 'code is required.';
        } elseif (!preg_match('/^[a-z0-9_-]+$/', $code)) {
            $errors[] = 'code may only contain lowercase letters, numbers, underscore, and dash.';
        }

        $type = $manifest['type'] ?? null;
        if (!is_string($type) || '' === trim($type)) {
            $errors[] = 'type is required.';
        } elseif (!in_array($type, Plugin::TYPES, true)) {
            $errors[] = 'type is not supported.';
        }

        if (!is_string($manifest['version'] ?? null) || '' === trim((string) $manifest['version'])) {
            $errors[] = 'version is required.';
        }

        if (!isset($manifest['name']) || !is_array($manifest['name']) || [] === $manifest['name']) {
            $errors[] = 'name is required.';
        }

        if (!is_string($manifest['mainClass'] ?? null) || '' === trim((string) $manifest['mainClass'])) {
            $errors[] = 'mainClass is required.';
        }

        return [] === $errors ? ValidationResult::valid() : ValidationResult::invalid($errors);
    }

    public function enable(Plugin $plugin): void
    {
        if (Plugin::TYPE_PAYMENT_GATEWAY === $plugin->getType()) {
            $doctor = $this->doctor->inspect($plugin);
            if (!$doctor->ok()) {
                $plugin
                    ->setStatus(Plugin::STATUS_ERROR)
                    ->setErrorMessage($doctor->errorMessage())
                    ->setUpdatedAt(new \DateTimeImmutable());
                $this->entityManager->flush();

                return;
            }
        }

        $plugin
            ->setStatus(Plugin::STATUS_ENABLED)
            ->setEnabledAt(new \DateTimeImmutable())
            ->setErrorMessage(null)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }

    public function disable(Plugin $plugin): void
    {
        $plugin
            ->setStatus(Plugin::STATUS_DISABLED)
            ->setDisabledAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }

    public function uninstall(Plugin $plugin): void
    {
        throw new \LogicException('Plugin uninstall is not available in this phase.');
    }

    /**
     * @return list<Plugin>
     */
    public function listInstalled(): array
    {
        return $this->entityManager->getRepository(Plugin::class)
            ->createQueryBuilder('plugin')
            ->orderBy('plugin.installedAt', 'DESC')
            ->addOrderBy('plugin.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function validateZipSource(UploadedFile|string $zipPath): void
    {
        if ($zipPath instanceof UploadedFile) {
            if (!$zipPath->isValid()) {
                throw new PluginInstallException('Uploaded plugin ZIP is invalid.');
            }
            $extension = strtolower($zipPath->getClientOriginalExtension());
            if ('zip' !== $extension) {
                throw new PluginInstallException('Only .zip plugin packages are accepted.');
            }
            if ($zipPath->getSize() !== false && $zipPath->getSize() > $this->maxUploadBytes) {
                throw new PluginInstallException('Plugin ZIP exceeds the maximum upload size.');
            }

            return;
        }

        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new PluginInstallException('Plugin ZIP file was not found.');
        }
        if (strtolower(pathinfo($zipPath, PATHINFO_EXTENSION)) !== 'zip') {
            throw new PluginInstallException('Only .zip plugin packages are accepted.');
        }
        $size = filesize($zipPath);
        if ($size !== false && $size > $this->maxUploadBytes) {
            throw new PluginInstallException('Plugin ZIP exceeds the maximum upload size.');
        }
    }

    private function createTempDir(): string
    {
        $base = $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'plugin_uploads'.\DIRECTORY_SEPARATOR.'tmp';
        $this->ensureDirectory($base);

        $dir = $base.\DIRECTORY_SEPARATOR.bin2hex(random_bytes(16));
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new PluginInstallException('Could not create upload workspace.');
        }

        return $dir;
    }

    private function extractZipSafely(string $zipPath, string $targetDir): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new PluginInstallException('PHP Zip extension is required to install plugins.');
        }

        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            throw new PluginInstallException('Invalid ZIP file.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || !$this->isSafeArchivePath($name)) {
                    throw new PluginInstallException('Plugin ZIP contains an unsafe path.');
                }
                if ($this->isIgnoredArchivePath($name)) {
                    continue;
                }
                if (!$this->isAllowedPluginPath($name)) {
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

    private function isSafeArchivePath(string $path): bool
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

    private function isAllowedPluginPath(string $path): bool
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        if ('' === $normalized) {
            return true;
        }

        if ($this->isIgnoredArchivePath($normalized)) {
            return true;
        }

        $parts = explode('/', $normalized);
        if (array_intersect($parts, ['.git', 'vendor', 'public', 'var', 'cache'])) {
            return false;
        }

        $root = $parts[0];
        if ('plugin.json' === $root || 'README.md' === $root) {
            return 1 === count($parts);
        }

        if (in_array($root, self::ALLOWED_ROOTS, true)) {
            return true;
        }

        if (count($parts) >= 2) {
            $nestedRoot = $parts[1];
            if ('plugin.json' === $nestedRoot || 'README.md' === $nestedRoot) {
                return 2 === count($parts);
            }

            return in_array($nestedRoot, self::ALLOWED_ROOTS, true);
        }

        return false;
    }

    private function isIgnoredArchivePath(string $path): bool
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        $basename = basename($normalized);

        return '' === $normalized
            || '__MACOSX' === $normalized
            || str_starts_with($normalized, '__MACOSX/')
            || '.DS_Store' === $basename
            || str_starts_with($basename, '._');
    }

    private function normalizeExtractedRoot(string $tmpDir): string
    {
        if (is_file($tmpDir.\DIRECTORY_SEPARATOR.'plugin.json')) {
            return $tmpDir;
        }

        $entries = array_values(array_filter(scandir($tmpDir) ?: [], fn (string $entry): bool => !in_array($entry, ['.', '..', '__MACOSX', '.DS_Store'], true) && !str_starts_with($entry, '._')));
        if (1 === count($entries) && is_dir($tmpDir.\DIRECTORY_SEPARATOR.$entries[0])) {
            return $tmpDir.\DIRECTORY_SEPARATOR.$entries[0];
        }

        return $tmpDir;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $manifestPath): array
    {
        $json = file_get_contents($manifestPath);
        if (false === $json) {
            throw new PluginInstallException('Could not read plugin.json.');
        }

        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PluginInstallException('plugin.json must contain valid JSON.');
        }

        if (!is_array($manifest)) {
            throw new PluginInstallException('plugin.json must contain a JSON object.');
        }

        return $manifest;
    }

    private function pluginDir(string $code): string
    {
        return $this->projectDir.\DIRECTORY_SEPARATOR.'var'.\DIRECTORY_SEPARATOR.'plugins'.\DIRECTORY_SEPARATOR.$code;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new PluginInstallException('Could not create plugin storage directory.');
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private function relativeProjectPath(string $path): string
    {
        $normalizedProject = rtrim(str_replace('\\', '/', $this->projectDir), '/').'/';
        $normalizedPath = str_replace('\\', '/', $path);

        return str_starts_with($normalizedPath, $normalizedProject)
            ? substr($normalizedPath, strlen($normalizedProject))
            : $normalizedPath;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
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

    /**
     * @param array<string, mixed> $manifest
     *
     * @return list<string>|null
     */
    private function permissionsFromManifest(array $manifest): ?array
    {
        $permissions = $manifest['permissions'] ?? null;
        if (!is_array($permissions)) {
            return null;
        }

        $result = [];
        foreach ($permissions as $permission) {
            if (is_string($permission) && '' !== trim($permission)) {
                $result[] = trim($permission);
            }
        }

        return [] === $result ? null : array_values(array_unique($result));
    }
}
