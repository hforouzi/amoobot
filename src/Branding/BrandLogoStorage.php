<?php

declare(strict_types=1);

namespace App\Branding;

use App\Subscription\Application\StoredFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The brand logo file (BrandingSettings, "branding" settings tab). It lives outside public/ (APP_BRANDING_DIR, default
 * var/branding) under a random name and is only served by /brand/logo/{name} (BrandLogoController), like the app
 * downloads (AppDownloadStorage).
 *
 * An upload is accepted when it is a PNG, JPEG or WebP image (never SVG or anything else): an allowed extension that
 * matches the content, the format's signature at the start and its end marker at the end (no truncated file, nothing
 * appended: no polyglot), finfo agreeing, at most MAX_BYTES, and a size between MIN_SIDE and MAX_SIDE pixels on each
 * side, read from the header before anything is decoded. It is then decoded with GD and encoded again in the same
 * format: only the pixels are kept (no EXIF, comments, colour profiles or other chunks). The content type it is served
 * with comes from the stored name, never from what the browser claimed.
 */
final class BrandLogoStorage
{
    public const MAX_BYTES = 512 * 1024;
    public const MIN_SIDE = 16;
    public const MAX_SIDE = 1024;

    /** Stored extension => [content type it is served with, IMAGETYPE_* of the content, IMG_* GD needs]. */
    public const TYPES = [
        'png' => ['image/png', IMAGETYPE_PNG, IMG_PNG],
        'jpg' => ['image/jpeg', IMAGETYPE_JPEG, IMG_JPG],
        'webp' => ['image/webp', IMAGETYPE_WEBP, IMG_WEBP],
    ];

    /** Extensions an upload may have => the stored extension. */
    private const EXTENSIONS = ['png' => 'png', 'jpg' => 'jpg', 'jpeg' => 'jpg', 'webp' => 'webp'];

    public const STORED_NAME = '/^[a-f0-9]{32}\.(?:png|jpg|webp)$/D';

    public function __construct(
        #[Autowire('%app.branding_dir%')]
        private readonly string $directory,
    ) {
    }

    public static function allowedExtensions(): string
    {
        return implode(', ', array_keys(self::EXTENSIONS));
    }

    /**
     * @throws BrandLogoRejected
     */
    public function store(UploadedFile $file): StoredFile
    {
        $maxKb = ['%max%' => (string) intdiv(self::MAX_BYTES, 1024)];
        if (!$file->isValid()) {
            throw new BrandLogoRejected('branding.logo.failed', $maxKb);
        }

        $extension = self::EXTENSIONS[strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION))] ?? null;
        if (null === $extension) {
            throw new BrandLogoRejected('branding.logo.extension', ['%allowed%' => self::allowedExtensions()]);
        }

        $size = (int) $file->getSize();
        if ($size <= 0) {
            throw new BrandLogoRejected('branding.logo.empty');
        }
        if ($size > self::MAX_BYTES) {
            throw new BrandLogoRejected('branding.logo.too_large', $maxKb);
        }
        $bytes = (string) file_get_contents($file->getPathname(), false, null, 0, self::MAX_BYTES + 1);
        if ('' === $bytes) {
            throw new BrandLogoRejected('branding.logo.empty');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new BrandLogoRejected('branding.logo.too_large', $maxKb);
        }

        [$contentType, $imageType, $gdType] = self::TYPES[$extension];
        $detected = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        if (self::signature($bytes) !== $extension || $detected !== $contentType) {
            throw new BrandLogoRejected('branding.logo.content_type', ['%allowed%' => self::allowedExtensions()]);
        }
        if (!self::complete($bytes, $extension)) {
            throw new BrandLogoRejected('branding.logo.incomplete');
        }

        $info = @getimagesizefromstring($bytes);
        if (false === $info || $imageType !== $info[2]) {
            throw new BrandLogoRejected('branding.logo.decode');
        }
        [$width, $height] = $info;
        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE || $width > self::MAX_SIDE || $height > self::MAX_SIDE) {
            throw new BrandLogoRejected('branding.logo.dimensions', ['%min%' => (string) self::MIN_SIDE, '%max%' => (string) self::MAX_SIDE]);
        }
        if (0 === (imagetypes() & $gdType)) {
            throw new BrandLogoRejected('branding.logo.unsupported', ['%type%' => $contentType]);
        }

        $encoded = self::reencode($bytes, $extension, $width, $height);
        $storedName = bin2hex(random_bytes(16)).'.'.$extension;
        $this->ensureDirectory();
        if (false === @file_put_contents($this->directory.'/'.$storedName, $encoded, LOCK_EX)) {
            throw new \RuntimeException('The branding directory is not writable.');
        }
        @chmod($this->directory.'/'.$storedName, 0640);

        return new StoredFile($storedName, self::sanitizeName($file->getClientOriginalName(), $extension), $contentType, strlen($encoded), hash('sha256', $encoded));
    }

    public function path(string $storedName): string
    {
        if (1 !== preg_match(self::STORED_NAME, $storedName)) {
            throw new \InvalidArgumentException('Not a stored logo file.');
        }

        return $this->directory.'/'.$storedName;
    }

    public function exists(?string $storedName): bool
    {
        return null !== $storedName && 1 === preg_match(self::STORED_NAME, $storedName) && is_file($this->path($storedName));
    }

    public function delete(?string $storedName): void
    {
        if ($this->exists($storedName)) {
            @unlink($this->path((string) $storedName));
        }
    }

    public static function contentType(string $storedName): string
    {
        return self::TYPES[pathinfo($storedName, PATHINFO_EXTENSION)][0] ?? 'application/octet-stream';
    }

    /** ASCII letters, digits, ".", "-" and "_" only, at most 120 characters, ending with the stored extension. */
    public static function sanitizeName(string $originalName, string $extension): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $originalName)), PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
        $base = (string) preg_replace('/-{2,}/', '-', $base);
        $base = trim($base, '-._');
        $base = '' === $base ? 'logo' : substr($base, 0, 120);

        return $base.'.'.$extension;
    }

    /** The stored extension the content starts like, or null. */
    private static function signature(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpg',
            'RIFF' === substr($bytes, 0, 4) && 'WEBP' === substr($bytes, 8, 4) => 'webp',
            default => null,
        };
    }

    /** The file ends exactly where the image ends: the PNG IEND chunk, the JPEG EOI marker, the WebP RIFF length. */
    private static function complete(string $bytes, string $extension): bool
    {
        return match ($extension) {
            'png' => str_ends_with($bytes, "IEND\xAE\x42\x60\x82"),
            'jpg' => str_ends_with($bytes, "\xFF\xD9"),
            'webp' => strlen($bytes) >= 12 && unpack('V', substr($bytes, 4, 4))[1] + 8 === strlen($bytes),
            default => false,
        };
    }

    /**
     * Decoded and encoded again: the pixels only. Transparency is kept (PNG, WebP).
     *
     * @throws BrandLogoRejected
     */
    private static function reencode(string $bytes, string $extension, int $width, int $height): string
    {
        $image = @imagecreatefromstring($bytes);
        if (false === $image) {
            throw new BrandLogoRejected('branding.logo.decode');
        }
        if (imagesx($image) !== $width || imagesy($image) !== $height) {
            throw new BrandLogoRejected('branding.logo.decode');
        }
        if ('jpg' !== $extension) {
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        ob_start();
        try {
            $written = match ($extension) {
                'png' => imagepng($image, null, 9),
                'jpg' => imagejpeg($image, null, 90),
                default => imagewebp($image, null, 90),
            };
        } finally {
            $encoded = (string) ob_get_clean();
        }
        if (!$written || '' === $encoded) {
            throw new BrandLogoRejected('branding.logo.decode');
        }

        return $encoded;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The branding directory cannot be created.');
        }
    }
}
