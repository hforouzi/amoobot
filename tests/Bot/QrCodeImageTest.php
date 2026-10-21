<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\Application\QrCodeImage;
use Endroid\QrCode\ErrorCorrectionLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zxing\QrReader;

/**
 * The QR codes sent to customers must scan in V2Box & co: rendered, then decoded back, as is and after the
 * recompression Telegram applies to photos (downscale to 320px + JPEG quality 75).
 */
final class QrCodeImageTest extends TestCase
{
    private const SUBSCRIPTION_URL = 'https://sub.example-shop.com:2096/sub/k3j9x7q2m1zt5w8a';
    private const VLESS_REALITY = 'vless://b831381d-6324-4d53-ad4f-8cda48b30811@edge-de-01.example-shop.com:443?type=tcp&security=reality&pbk=Z84J2IelR9ch3k8VtlVhhs5ycBUlXA7wHBWcBrjqnAw&fp=chrome&sni=www.speedtest.net&sid=a1b2c3d4e5f60718&spx=%2F&flow=xtls-rprx-vision&encryption=none#%F0%9F%87%A9%F0%9F%87%AA%20Germany-01%20%7C%2050GB%20%7C%2030%20days%20-%20tg_123456789_order_4521';

    /**
     * @return iterable<string, array{string}>
     */
    public static function contents(): iterable
    {
        yield 'subscription url' => [self::SUBSCRIPTION_URL];
        yield 'long vless reality link' => [self::VLESS_REALITY];
        yield 'vmess link' => ['vmess://'.base64_encode((string) json_encode(['v' => '2', 'ps' => 'Germany-01 | 50GB', 'add' => 'edge-de-01.example-shop.com', 'port' => '443', 'id' => 'b831381d-6324-4d53-ad4f-8cda48b30811', 'aid' => '0', 'net' => 'ws', 'type' => 'none', 'host' => 'edge-de-01.example-shop.com', 'path' => '/ws?ed=2048', 'tls' => 'tls', 'sni' => 'edge-de-01.example-shop.com']))];
    }

    #[DataProvider('contents')]
    public function testTheImageDecodesBackToTheContent(string $content): void
    {
        $png = QrCodeImage::png($content);

        self::assertSame($content, $this->decode($png));
    }

    #[DataProvider('contents')]
    public function testItStillDecodesAfterTelegramStyleRecompression(string $content): void
    {
        $jpeg = $this->recompress(QrCodeImage::png($content), 320, 75);

        self::assertSame($content, $this->decode($jpeg));
    }

    #[DataProvider('contents')]
    public function testEveryModuleIsAtLeastEightPixelsWithAFourModuleQuietZone(string $content): void
    {
        $png = QrCodeImage::png($content);
        $modules = QrCodeImage::moduleCount($content);
        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);
        $width = imagesx($image);

        self::assertSame($width, imagesy($image));
        self::assertSame(0, $width % ($modules + 8), 'whole pixels per module, 4 quiet modules each side');
        $modulePx = intdiv($width, $modules + 8);
        self::assertGreaterThanOrEqual(QrCodeImage::MIN_MODULE_PX, $modulePx);

        // Black on white: the quiet zone is white, the top-left finder pattern starts right after it.
        $quiet = 4 * $modulePx;
        self::assertSame(0xFFFFFF, imagecolorat($image, $quiet - 1, $quiet - 1) & 0xFFFFFF);
        self::assertSame(0x000000, imagecolorat($image, $quiet, $quiet) & 0xFFFFFF);
    }

    public function testErrorCorrectionAndDelivery(): void
    {
        self::assertSame(ErrorCorrectionLevel::High, QrCodeImage::errorCorrection(self::SUBSCRIPTION_URL));
        self::assertSame(ErrorCorrectionLevel::Medium, QrCodeImage::errorCorrection(self::VLESS_REALITY));
        self::assertTrue(QrCodeImage::fitsInAPhoto(self::SUBSCRIPTION_URL), 'a short URL survives Telegram photo compression');
        self::assertFalse(QrCodeImage::fitsInAPhoto(self::VLESS_REALITY), 'a long link is sent as an uncompressed document');
    }

    private function decode(string $image): ?string
    {
        $text = (new QrReader($image, QrReader::SOURCE_TYPE_BLOB))->text(['TRY_HARDER' => true]);

        return is_string($text) ? $text : null;
    }

    private function recompress(string $png, int $width, int $quality): string
    {
        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);
        $scaled = imagescale($image, $width, (int) round(imagesy($image) * $width / imagesx($image)), IMG_BICUBIC);
        self::assertNotFalse($scaled);
        ob_start();
        imagejpeg($scaled, null, $quality);

        return (string) ob_get_clean();
    }
}
