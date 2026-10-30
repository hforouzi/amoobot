<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Image files for the brand logo tests, made with GD at run time (no binary fixtures). JPEG and WebP depend on the GD
 * build: tests that need them skip when supports() says no (one dev machine has GD without JPEG).
 */
final class BrandImages
{
    public static function supports(int $gdType): bool
    {
        return 0 !== (imagetypes() & $gdType);
    }

    /** A truecolor PNG with a transparent background and an opaque square in the middle. */
    public static function png(int $width = 64, int $height = 64): string
    {
        $image = self::image($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, intdiv($width, 4), intdiv($height, 4), intdiv(3 * $width, 4), intdiv(3 * $height, 4), (int) imagecolorallocatealpha($image, 22, 101, 52, 0));

        return self::capture(static fn () => imagepng($image));
    }

    public static function jpeg(int $width = 64, int $height = 64): string
    {
        $image = self::image($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 22, 101, 52));

        return self::capture(static fn () => imagejpeg($image, null, 90));
    }

    public static function webp(int $width = 64, int $height = 64): string
    {
        $image = self::image($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 22, 101, 52));

        return self::capture(static fn () => imagewebp($image, null, 90));
    }

    /** A PNG with a tEXt chunk (metadata) holding $text, before IEND. */
    public static function pngWithText(string $text): string
    {
        $png = self::png();

        return substr($png, 0, -12).self::chunk('tEXt', "Comment\0".$text).substr($png, -12);
    }

    /** A JPEG with an EXIF (APP1) segment holding $text, right after the start marker. */
    public static function jpegWithExif(string $text): string
    {
        $payload = "Exif\0\0".$text;

        return "\xFF\xD8"."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr(self::jpeg(), 2);
    }

    /** A small, complete PNG whose header claims $width x $height pixels (nothing to decode at that size). */
    public static function pngClaiming(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1A\n"
            .self::chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
            .self::chunk('IDAT', (string) gzcompress(str_repeat("\0", 64)))
            .self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function image(int $width, int $height): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('GD cannot create an image.');
        }

        return $image;
    }

    private static function capture(callable $write): string
    {
        ob_start();
        $write();

        return (string) ob_get_clean();
    }
}
