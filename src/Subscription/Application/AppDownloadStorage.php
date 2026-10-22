<?php

declare(strict_types=1);

namespace App\Subscription\Application;

use App\Entity\AppDownload;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Installer files of app downloads. They live outside public/ (APP_DOWNLOADS_DIR, default var/downloads) under a
 * random name and are only served by /d/{id} (AppDownloadController).
 *
 * An upload is accepted when its extension is on the allow-list, it is not larger than APP_DOWNLOADS_MAX_MB (default
 * 200) and its content looks like that kind of file (finfo; anything detected as text, an image, HTML or a script is
 * refused even with an installer extension). The content type it is served with comes from the extension, never
 * from what the browser claimed.
 */
final class AppDownloadStorage
{
    /** extension => [content type it is served with, content types finfo may detect for it] */
    public const TYPES = [
        'apk' => ['application/vnd.android.package-archive', ['application/vnd.android.package-archive', 'application/zip', 'application/java-archive', 'application/x-zip-compressed', 'application/octet-stream']],
        // A zip may well hold an APK or a JAR (finfo then names those).
        'zip' => ['application/zip', ['application/zip', 'application/x-zip-compressed', 'application/vnd.android.package-archive', 'application/java-archive', 'application/octet-stream']],
        'exe' => ['application/vnd.microsoft.portable-executable', ['application/x-dosexec', 'application/vnd.microsoft.portable-executable', 'application/x-msdownload', 'application/octet-stream']],
        'msi' => ['application/x-msi', ['application/x-msi', 'application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', 'application/vnd.ms-msi', 'application/octet-stream']],
        'dmg' => ['application/x-apple-diskimage', ['application/x-apple-diskimage', 'application/x-bzip2', 'application/zlib', 'application/x-xz', 'application/octet-stream']],
        'pkg' => ['application/octet-stream', ['application/x-xar', 'application/octet-stream']],
        'appimage' => ['application/vnd.appimage', ['application/x-executable', 'application/x-pie-executable', 'application/x-elf', 'application/x-sharedlib', 'application/vnd.appimage', 'application/octet-stream']],
        'deb' => ['application/vnd.debian.binary-package', ['application/vnd.debian.binary-package', 'application/x-debian-package', 'application/x-archive', 'application/octet-stream']],
    ];

    private const STORED_NAME = '/^[a-f0-9]{32}$/';

    private int $maxBytes;

    public function __construct(
        #[Autowire('%app.downloads_dir%')]
        private readonly string $directory,
        #[Autowire('%app.downloads_max_mb%')]
        int $maxMegabytes,
    ) {
        $this->maxBytes = max(1, $maxMegabytes) * 1024 * 1024;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /** The same storage with another size limit (tests, a stricter caller). */
    public function withMaxBytes(int $maxBytes): self
    {
        $copy = clone $this;
        $copy->maxBytes = $maxBytes;

        return $copy;
    }

    public static function allowedExtensions(): string
    {
        return implode(', ', array_keys(self::TYPES));
    }

    /**
     * @throws AppDownloadRejected
     */
    public function store(UploadedFile $file): StoredFile
    {
        if (!$file->isValid()) {
            throw new AppDownloadRejected('app_download.upload.failed', ['%max%' => (string) intdiv($this->maxBytes, 1024 * 1024)]);
        }

        $extension = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            throw new AppDownloadRejected('app_download.upload.extension', ['%allowed%' => self::allowedExtensions()]);
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new AppDownloadRejected('app_download.upload.empty');
        }
        if ($size > $this->maxBytes) {
            throw new AppDownloadRejected('app_download.upload.too_large', ['%max%' => (string) intdiv($this->maxBytes, 1024 * 1024)]);
        }

        [$servedType, $acceptedTypes] = self::TYPES[$extension];
        $detected = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()));
        if (!in_array($detected, $acceptedTypes, true)) {
            throw new AppDownloadRejected('app_download.upload.content_type', ['%type%' => $detected]);
        }

        $sha256 = (string) hash_file('sha256', $file->getPathname());
        $storedName = bin2hex(random_bytes(16));
        $this->ensureDirectory();
        $file->move($this->directory, $storedName);
        @chmod($this->directory.'/'.$storedName, 0640);

        return new StoredFile($storedName, self::sanitizeName($file->getClientOriginalName(), $extension), $servedType, $size, $sha256);
    }

    public function path(AppDownload $download): string
    {
        $name = (string) $download->getStoredName();
        if (1 !== preg_match(self::STORED_NAME, $name)) {
            throw new \InvalidArgumentException('Not a stored download file.');
        }

        return $this->directory.'/'.$name;
    }

    public function exists(AppDownload $download): bool
    {
        return null !== $download->getStoredName() && is_file($this->path($download));
    }

    public function delete(AppDownload $download): void
    {
        if (null !== $download->getStoredName() && 1 === preg_match(self::STORED_NAME, (string) $download->getStoredName())) {
            @unlink($this->path($download));
        }
    }

    /** ASCII letters, digits, ".", "-" and "_" only, at most 120 characters, ending with the (lowercase) extension. */
    public static function sanitizeName(string $originalName, string $extension): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $originalName)), PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
        $base = (string) preg_replace('/-{2,}/', '-', $base);
        $base = trim($base, '-._');
        $base = '' === $base ? 'download' : substr($base, 0, 120);

        return $base.'.'.$extension;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The downloads directory cannot be created.');
        }
    }
}
