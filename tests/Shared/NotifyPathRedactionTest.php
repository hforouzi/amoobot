<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Shared\Infrastructure\RedactingLogger;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A gateway notify URL (/payment/notify/tetraminator/<id>/<token>) carries a webhook token: whatever follows
 * /payment/notify/ is masked in every log line (forLog(), so RedactingLogger) and by redact(). A backstop: the
 * endpoint itself never logs the URL.
 */
final class NotifyPathRedactionTest extends TestCase
{
    private const TOKEN = 'Ab3dEf6hIj9lMn0pQr1tUv2xYz4_-5bC7dE8fG9hI0j';

    /**
     * @return iterable<string, array{string}>
     */
    public static function texts(): iterable
    {
        yield 'request line' => ['GET /payment/notify/tetraminator/12/'.self::TOKEN.' HTTP/1.1'];
        yield 'absolute URL with a query' => ['https://shop.example/payment/notify/tetraminator/12/'.self::TOKEN.'?x=1'];
        yield 'Symfony routing exception' => ['No route found for "POST http://localhost/payment/notify/tetraminator/12/'.self::TOKEN.'": Method Not Allowed'];
        yield 'JSON-escaped slashes' => [(string) json_encode(['callback_url' => 'https://shop.example/payment/notify/tetraminator/12/'.self::TOKEN])];
        yield 'URL-encoded' => ['callback_url='.rawurlencode('https://shop.example/payment/notify/tetraminator/12/'.self::TOKEN)];
        yield 'upper case' => ['/PAYMENT/NOTIFY/tetraminator/12/'.self::TOKEN];
    }

    #[DataProvider('texts')]
    public function testTheTokenIsMaskedByBothRedactors(string $text): void
    {
        foreach ([SecretRedactor::forLog($text), SecretRedactor::redact($text)] as $masked) {
            self::assertStringNotContainsString(self::TOKEN, $masked);
            self::assertStringNotContainsString('tetraminator/12', $masked);
            self::assertStringContainsString(SecretRedactor::MASK, $masked);
        }
    }

    public function testTheLoggerMasksMessageAndContext(): void
    {
        $recorder = new RecordingLogger(new NullLogger());
        $url = 'https://shop.example/payment/notify/tetraminator/12/'.self::TOKEN;

        (new RedactingLogger($recorder))->error('Request to '.$url.' failed.', ['url' => $url, 'nested' => ['e' => new \RuntimeException('at '.$url)]]);

        self::assertStringNotContainsString(self::TOKEN, $recorder->text());
        self::assertStringContainsString('/payment/notify/'.SecretRedactor::MASK, $recorder->text());
    }

    public function testOtherPathsAreLeftAlone(): void
    {
        $text = 'GET /payment/return/abc /payment/callback/plugin/winapay?Authority=A1 /payment/notifyx/1 /telegram/webhook';

        self::assertSame($text, SecretRedactor::forLog($text));
        self::assertSame($text, SecretRedactor::redact($text));
    }
}
