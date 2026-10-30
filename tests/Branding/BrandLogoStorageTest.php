<?php

declare(strict_types=1);

namespace App\Tests\Branding;

use App\Branding\BrandLogoRejected;
use App\Branding\BrandLogoStorage;
use App\Tests\Support\BrandImages;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * What a brand logo upload must be (BrandLogoStorage): a PNG, JPEG or WebP whose extension, signature, end marker and
 * finfo agree, within the size and pixel limits, decoded and re-encoded (no metadata kept), stored outside public/
 * under a random name.
 */
final class BrandLogoStorageTest extends IntegrationTestCase
{
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir().'/amoobot-logo-test-'.bin2hex(random_bytes(4));
        mkdir($this->work);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->work.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->work);
        foreach (glob($this->directory().'/*') ?: [] as $file) {
            unlink($file);
        }
        parent::tearDown();
    }

    public function testAPngIsReencodedAndStoredOutsidePublicUnderARandomName(): void
    {
        $stored = $this->storage()->store($this->file('My Logo ☺.png', BrandImages::png(64, 48)));

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $stored->storedName);
        self::assertSame('image/png', $stored->mimeType);
        self::assertSame('My-Logo.png', $stored->originalName);
        $path = $this->storage()->path($stored->storedName);
        self::assertFileExists($path);
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        self::assertStringStartsNotWith(str_replace('\\', '/', $projectDir.'/public'), str_replace('\\', '/', (string) realpath($path)));
        self::assertSame(hash_file('sha256', $path), $stored->sha256);
        self::assertSame(filesize($path), $stored->sizeBytes);

        $image = imagecreatefrompng($path);
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertSame([64, 48], [imagesx($image), imagesy($image)]);
        self::assertSame(127, (imagecolorat($image, 0, 0) >> 24) & 0x7F, 'the transparent background is kept');
        self::assertSame(0, (imagecolorat($image, 32, 24) >> 24) & 0x7F);
    }

    public function testADoubleExtensionEndingInAnImageTypeIsStoredUnderItsOwnRandomName(): void
    {
        $stored = $this->storage()->store($this->file('logo.php.png', BrandImages::png()));

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $stored->storedName);
        self::assertSame('logo.php.png', $stored->originalName, 'only a display name; never a path');
    }

    public function testPngMetadataIsNotKept(): void
    {
        $stored = $this->storage()->store($this->file('logo.png', BrandImages::pngWithText('SECRET-AUTHOR-42')));

        $bytes = (string) file_get_contents($this->storage()->path($stored->storedName));
        self::assertStringNotContainsString('SECRET-AUTHOR-42', $bytes);
        self::assertStringNotContainsString('tEXt', $bytes);
    }

    public function testJpegExifIsNotKept(): void
    {
        if (!BrandImages::supports(IMG_JPG)) {
            self::markTestSkipped('GD has no JPEG support here.');
        }
        $stored = $this->storage()->store($this->file('photo.JPEG', BrandImages::jpegWithExif('GPS-35.6892-51.3890')));

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.jpg$/', $stored->storedName);
        self::assertSame('image/jpeg', $stored->mimeType);
        $bytes = (string) file_get_contents($this->storage()->path($stored->storedName));
        self::assertStringNotContainsString('GPS-35.6892', $bytes);
        self::assertStringNotContainsString('Exif', $bytes);
    }

    public function testWebpIsAccepted(): void
    {
        if (!BrandImages::supports(IMG_WEBP)) {
            self::markTestSkipped('GD has no WebP support here.');
        }
        $stored = $this->storage()->store($this->file('logo.webp', BrandImages::webp()));

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.webp$/', $stored->storedName);
        self::assertSame('image/webp', $stored->mimeType);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refused(): iterable
    {
        $png = BrandImages::png();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><script>alert(1)</script></svg>';
        // A JPEG start marker followed by junk: never decoded (the content check comes first), so no GD JPEG needed.
        $jpegLike = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\x00", 64)."\xFF\xD9";

        yield 'svg file' => ['logo.svg', $svg, 'branding.logo.extension'];
        yield 'svg named png' => ['logo.png', $svg, 'branding.logo.content_type'];
        yield 'svg with a png signature in front' => ['logo.png', "\x89PNG\r\n\x1A\n".$svg, 'branding.logo.content_type'];
        yield 'html named png' => ['logo.png', '<!doctype html><html><body>hi</body></html>', 'branding.logo.content_type'];
        yield 'gif' => ['logo.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'branding.logo.extension'];
        yield 'php after an image extension' => ['logo.png.php', $png, 'branding.logo.extension'];
        yield 'no extension' => ['logo', $png, 'branding.logo.extension'];
        yield 'jpeg content named png' => ['logo.png', $jpegLike, 'branding.logo.content_type'];
        yield 'png content named jpg' => ['logo.jpg', $png, 'branding.logo.content_type'];
        yield 'png content named webp' => ['logo.webp', $png, 'branding.logo.content_type'];
        yield 'zero bytes' => ['logo.png', '', 'branding.logo.empty'];
        yield 'truncated png' => ['logo.png', substr($png, 0, intdiv(strlen($png), 2)), 'branding.logo.incomplete'];
        yield 'polyglot: php after the png' => ['logo.png', $png.'<?php system($_GET["c"]); ?>', 'branding.logo.incomplete'];
        yield 'polyglot: html after the png' => ['logo.png', $png.'<html><script>alert(1)</script></html>', 'branding.logo.incomplete'];
        yield 'polyglot: zip after the png' => ['logo.png', $png."PK\x03\x04".str_repeat("\x00", 26), 'branding.logo.incomplete'];
        yield 'over 512 KB' => ['logo.png', $png.str_repeat("\x00", BrandLogoStorage::MAX_BYTES), 'branding.logo.too_large'];
        yield 'huge dimensions (decompression bomb header)' => ['logo.png', BrandImages::pngClaiming(20000, 20000), 'branding.logo.dimensions'];
        yield 'wider than the limit' => ['logo.png', BrandImages::png(BrandLogoStorage::MAX_SIDE + 1, 32), 'branding.logo.dimensions'];
        yield 'smaller than the limit' => ['logo.png', BrandImages::png(8, 8), 'branding.logo.dimensions'];
        yield 'complete png with pixel data that cannot be decoded' => ['logo.png', BrandImages::pngClaiming(64, 64), 'branding.logo.decode'];
    }

    #[DataProvider('refused')]
    public function testARefusedUploadIsNotStored(string $name, string $content, string $reason): void
    {
        try {
            $this->storage()->store($this->file($name, $content));
            self::fail('accepted');
        } catch (BrandLogoRejected $e) {
            self::assertSame($reason, $e->getMessage());
        }
        self::assertSame([], glob($this->directory().'/*') ?: [], 'nothing written');
    }

    public function testATruncatedJpegIsRefused(): void
    {
        if (!BrandImages::supports(IMG_JPG)) {
            self::markTestSkipped('GD has no JPEG support here.');
        }
        $jpeg = BrandImages::jpeg();

        $this->expectExceptionMessage('branding.logo.incomplete');
        $this->storage()->store($this->file('logo.jpg', substr($jpeg, 0, -40)));
    }

    public function testAFailedUploadIsRefused(): void
    {
        $path = $this->work.'/partial';
        file_put_contents($path, BrandImages::png());

        $this->expectExceptionMessage('branding.logo.failed');
        $this->storage()->store(new UploadedFile($path, 'logo.png', 'image/png', UPLOAD_ERR_PARTIAL, true));
    }

    public function testOnlyStoredNamesMapToAPath(): void
    {
        foreach (['../.env', '..\\..\\.env', '/etc/passwd', 'logo.png', str_repeat('a', 32).'.svg', str_repeat('a', 32).'.png/../x', str_repeat('a', 32).".png\n"] as $name) {
            self::assertFalse($this->storage()->exists($name), $name);
            try {
                $this->storage()->path($name);
                self::fail('path for '.$name);
            } catch (\InvalidArgumentException) {
            }
        }
    }

    private function file(string $name, string $content): UploadedFile
    {
        $path = $this->work.'/'.bin2hex(random_bytes(6));
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function storage(): BrandLogoStorage
    {
        return self::getContainer()->get(BrandLogoStorage::class);
    }

    private function directory(): string
    {
        return (string) self::getContainer()->getParameter('app.branding_dir');
    }
}
