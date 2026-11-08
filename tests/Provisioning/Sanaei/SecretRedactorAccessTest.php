<?php

declare(strict_types=1);

namespace App\Tests\Provisioning\Sanaei;

use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecretRedactorAccessTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'subscription URL keeps scheme and host' => ['at https://sub.example.test:2096/sub/abc123 now', 'at https://sub.example.test:2096/[redacted] now'];
        yield 'telegram deep link stays' => ['https://t.me/amoobot?start=ref_42', 'https://t.me/amoobot?start=ref_42'];
        yield 'bare host stays' => ['https://panel.example.test/ is down', 'https://panel.example.test/ is down'];
        yield 'user info dropped' => ['https://admin:pw@panel.example.test/x', 'https://panel.example.test/[redacted]'];
        yield 'config links' => ["vless://11111111-2222-3333-4444-555555555555@h:443?x=1#n\ntrojan://pw@h:443", "vless://[redacted]\ntrojan://[redacted]"];
        yield 'page token without host' => ['/s/'.str_repeat('a', 43), '/s/[redacted]'];
        yield 'uuid' => ['client 11111111-2222-3333-4444-555555555555 gone', 'client [redacted] gone'];
        yield 'json keys' => ['{"subId":"s1","password":"p1","email":"e1"}', '{"subId":"[redacted]","password":"[redacted]","email":"e1"}'];
        yield 'masked hint stays' => ['uuid="••••5555" sub_id=raw', 'uuid="••••5555" sub_id="[redacted]"'];
        yield 'flag names untouched' => ['sub_id_present=yes', 'sub_id_present=yes'];
        yield 'query parameters' => ['failed with params ["s1", 2]: SQLSTATE', 'failed with params [[redacted]]: SQLSTATE'];
        yield 'telegram id in a client email' => ['clients/get/tg_7712345678_order_42 and xtg_1_order_2', 'clients/get/tg_[redacted]_order_42 and xtg_1_order_2'];
        yield 'duplicate entry' => ["Duplicate entry 's1' for key 'uniq'", "Duplicate entry '[redacted]' for key 'uniq'"];
    }

    #[DataProvider('texts')]
    public function testMaskAccess(string $text, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::maskAccess($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function apiKeyTexts(): iterable
    {
        yield 'header' => ['X-API-KEY: FAKE-key-0123', 'X-API-KEY: [redacted]'];
        yield 'header, lower case' => ['x-api-key:FAKE-key-0123 sent', 'x-api-key:[redacted] sent'];
        yield 'json header' => ['{"X-API-KEY":"FAKE-key-0123","Accept":"application/json"}', '{"X-API-KEY":"[redacted]","Accept":"application/json"}'];
        yield 'json field' => ['{"api_key":"FAKE-key-0123"}', '{"api_key":"[redacted]"}'];
        yield 'query' => ['/x?api_key=FAKE-key-0123&y=1', '/x?api_key=[redacted]&y=1'];
        yield 'dash and no separator' => ['api-key=FAKE-key-0123 apikey: FAKE-key-0123', 'api-key=[redacted] apikey: [redacted]'];
        yield 'php array' => ["'api_key' => 'FAKE-key-0123'", "'api_key' => '[redacted]'"];
        yield 'field name alone stays' => ['api_key is not configured', 'api_key is not configured'];
    }

    #[DataProvider('apiKeyTexts')]
    public function testRedactMasksApiKeyHeadersAndFields(string $text, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::redact($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function webhookSecretTexts(): iterable
    {
        yield 'signature header' => ['X-HooshPay-Signature: 0f3a9c', 'X-HooshPay-Signature: [redacted]'];
        yield 'signature header, json' => ['{"X-HooshPay-Signature":"0f3a9c"}', '{"X-HooshPay-Signature":"[redacted]"}'];
        yield 'secret field, json' => ['{"api_secret":"FAKE-secret-0123"}', '{"api_secret":"[redacted]"}'];
        yield 'secret field, form' => ['api_secret=FAKE-secret-0123&x=1', 'api_secret=[redacted]&x=1'];
        yield 'secret field, php array' => ["'api_secret' => 'FAKE-secret-0123'", "'api_secret' => '[redacted]'"];
        yield 'field name alone stays' => ['api_secret is missing', 'api_secret is missing'];
    }

    #[DataProvider('webhookSecretTexts')]
    public function testRedactMasksWebhookSignaturesAndGatewaySecrets(string $text, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::redact($text));
    }

    public function testRedactAccessAlsoFoldsAndLimits(): void
    {
        self::assertSame('a [redacted] b', SecretRedactor::redactAccess("a\n11111111-2222-3333-4444-555555555555\nb"));
        self::assertSame(5, mb_strlen(SecretRedactor::redactAccess(str_repeat('x', 50), 5)));
    }
}
