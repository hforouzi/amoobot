<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\UI\Support\AdminJsonFormatter;
use PHPUnit\Framework\TestCase;

/**
 * The read-only JSON display: never throws, masks sensitive keys at any depth, survives invalid UTF-8, deep nesting
 * and very large values.
 */
final class AdminJsonFormatterTest extends TestCase
{
    public function testEmptyValues(): void
    {
        self::assertSame('', AdminJsonFormatter::toPrettyHtml(null));
        self::assertSame('', AdminJsonFormatter::toPrettyHtml(''));
        self::assertStringContainsString('[]', AdminJsonFormatter::toPrettyHtml([]));
    }

    public function testNestedDataIsPrettyPrintedAndEscaped(): void
    {
        $html = AdminJsonFormatter::toPrettyHtml(['a' => ['b' => ['c' => '<script>x</script>']], 'list' => [1, 2]]);

        self::assertStringStartsWith('<pre class="admin-json">', $html);
        self::assertStringContainsString('&quot;c&quot;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testSensitiveKeysAreMaskedAtAnyDepthAndCase(): void
    {
        $html = AdminJsonFormatter::toPrettyHtml([
            'api_key' => 'v1', 'apiKey' => 'v2', 'X-Api-Key' => 'v3', 'ipn_secret' => 'v4', 'password' => 'v5', 'Authorization' => 'v6',
            'nested' => ['access_token' => 'v7', 'deeper' => ['privateKey' => 'v8', 'list' => [['client_secret' => 'v9']]]],
            'tokenized' => ['token' => ['inner' => 'v10']],
            'kept' => 'visible-value',
        ]);

        foreach (range(1, 10) as $i) {
            self::assertStringNotContainsString('&quot;v'.$i.'&quot;', $html, 'v'.$i);
        }
        self::assertStringContainsString('visible-value', $html);
        self::assertStringContainsString('api_key', $html, 'keys stay visible');
    }

    public function testAJsonStringIsDecodedAndMasked(): void
    {
        $html = AdminJsonFormatter::toPrettyHtml('{"user":"u1","password":"p-should-not-show"}');

        self::assertStringContainsString('u1', $html);
        self::assertStringNotContainsString('p-should-not-show', $html);
    }

    public function testInvalidUtf8DeepNestingAndLargeValuesDoNotThrow(): void
    {
        self::assertStringContainsString('ok', AdminJsonFormatter::toPrettyHtml(['bad' => "\xB1\x31\xFF", 'k' => 'ok']));

        $deep = 'leaf';
        for ($i = 0; $i < 1000; ++$i) {
            $deep = ['d' => $deep];
        }
        self::assertStringStartsWith('<pre class="admin-json">', AdminJsonFormatter::toPrettyHtml($deep));

        $large = AdminJsonFormatter::toPrettyHtml(['blob' => str_repeat('x', 2_000_000)]);
        self::assertLessThan(300_000, strlen($large));
        self::assertStringContainsString('…', $large);
    }

    public function testScalarsAndObjects(): void
    {
        self::assertStringContainsString('42', AdminJsonFormatter::toPrettyHtml(42));
        self::assertStringContainsString('plain text', AdminJsonFormatter::toPrettyHtml('plain text'));
        self::assertStringStartsWith('<pre', AdminJsonFormatter::toPrettyHtml(new \stdClass()));
    }
}
