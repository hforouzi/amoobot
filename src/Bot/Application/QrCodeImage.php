<?php

declare(strict_types=1);

namespace App\Bot\Application;

use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * QR code images for customers, sized for phone scanners (V2Box, v2rayNG, ...): every module at least 8px, a quiet
 * zone of 4 modules, plain black on white. Short content gets error correction High; longer content Medium, since
 * a higher level adds modules and dense codes are what become unreadable once Telegram downscales them.
 * Checked by decoding in QrCodeImageTest, including after Telegram-style photo recompression.
 */
final class QrCodeImage
{
    public const MIN_MODULE_PX = 8;
    /** Up to this many bytes: error correction High, and the code survives being sent as a (recompressed) photo. */
    public const SHORT_CONTENT_BYTES = 120;
    private const QUIET_ZONE_MODULES = 4;
    /** Small codes are drawn with larger modules so the image is not tiny on screen. */
    private const MIN_IMAGE_PX = 512;

    public static function errorCorrection(string $content): ErrorCorrectionLevel
    {
        return strlen($content) <= self::SHORT_CONTENT_BYTES ? ErrorCorrectionLevel::High : ErrorCorrectionLevel::Medium;
    }

    /** Photo (compressed by Telegram) for short content such as a subscription URL; otherwise send a document. */
    public static function fitsInAPhoto(string $content): bool
    {
        return strlen($content) <= self::SHORT_CONTENT_BYTES;
    }

    /** Modules per side, quiet zone excluded. */
    public static function moduleCount(string $content): int
    {
        return (new MatrixFactory())->create(new QrCode(data: $content, errorCorrectionLevel: self::errorCorrection($content)))->getBlockCount();
    }

    /**
     * @throws \Throwable when the content does not fit in a QR code
     */
    public static function png(string $content): string
    {
        $modules = self::moduleCount($content);
        $total = $modules + 2 * self::QUIET_ZONE_MODULES;
        $modulePx = max(self::MIN_MODULE_PX, intdiv(self::MIN_IMAGE_PX + $total - 1, $total));

        $qrCode = new QrCode(
            data: $content,
            errorCorrectionLevel: self::errorCorrection($content),
            size: $modules * $modulePx,
            margin: self::QUIET_ZONE_MODULES * $modulePx,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return (new PngWriter())->write($qrCode)->getString();
    }
}
