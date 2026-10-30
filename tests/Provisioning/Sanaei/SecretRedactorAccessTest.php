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
        yield 'duplicate entry' => ["Duplicate entry 's1' for key 'uniq'", "Duplicate entry '[redacted]' for key 'uniq'"];
    }

    #[DataProvider('texts')]
    public function testMaskAccess(string $text, string $expected): void
    {
        self::assertSame($expected, SecretRedactor::maskAccess($text));
    }

    public function testRedactAccessAlsoFoldsAndLimits(): void
    {
        self::assertSame('a [redacted] b', SecretRedactor::redactAccess("a\n11111111-2222-3333-4444-555555555555\nb"));
        self::assertSame(5, mb_strlen(SecretRedactor::redactAccess(str_repeat('x', 50), 5)));
    }
}
