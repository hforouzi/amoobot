<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use PHPUnit\Framework\TestCase;

/**
 * The Mini App's own font and the stylesheet rules around it: Vazirmatn is served from public/miniapp/fonts with its
 * licence (no CDN), the client-app cards are not stretched by the app shell's full-height rule.
 */
final class MiniAppFontAssetsTest extends TestCase
{
    private const DIR = __DIR__.'/../../public/miniapp';

    public function testVazirmatnIsSelfHostedWithItsLicence(): void
    {
        $font = self::DIR.'/fonts/Vazirmatn-wght.woff2';
        self::assertFileExists($font);
        self::assertSame('wOF2', (string) file_get_contents($font, false, null, 0, 4), 'a woff2 file');
        self::assertLessThan(150 * 1024, (int) filesize($font), 'one variable file, not every static weight');
        self::assertStringContainsString('SIL Open Font License, Version 1.1', (string) file_get_contents(self::DIR.'/fonts/OFL.txt'));
    }

    public function testTheStylesheetLoadsItLocallyWithSwapAndAUnicodeRange(): void
    {
        $css = (string) file_get_contents(self::DIR.'/app.css');

        self::assertMatchesRegularExpression('/@font-face\s*\{[^}]*font-family:\s*Vazirmatn;[^}]*font-display:\s*swap;[^}]*src:\s*url\("fonts\/Vazirmatn-wght\.woff2[^}]*unicode-range:\s*U\+0000-00FF,[^}]*U\+0600-06FF/s', $css);
        self::assertDoesNotMatchRegularExpression('#url\(\s*["\']?(https?:)?//#i', $css, 'no third-party file');
        self::assertDoesNotMatchRegularExpression('/@import/i', $css);
        self::assertMatchesRegularExpression('/body\s*\{[^}]*font-family:\s*Vazirmatn,\s*system-ui,/s', $css, 'with a system fallback');
        self::assertMatchesRegularExpression('/\ncode\s*\{[^}]*ui-monospace/', $css, 'configs stay monospace');
    }

    public function testOnlyTheShellIsViewportTallNotTheClientAppCards(): void
    {
        $css = (string) file_get_contents(self::DIR.'/app.css');

        self::assertDoesNotMatchRegularExpression('/(^|\n)\.app\s*\{[^}]*min-height:\s*var\(--vh\)/', $css, '<article class="app"> (subscription/_apps) matched it');
        self::assertMatchesRegularExpression('/body > \.app\s*\{\s*min-height:\s*var\(--vh\)/', $css);
    }
}
