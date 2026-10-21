<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\UI\Support\AdminFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The admin cell formats: no secret part of a URL is shown, expiry counts calendar days, empty is "—", values are
 * escaped.
 */
final class AdminFormatTest extends TestCase
{
    private AdminFormat $format;

    protected function setUp(): void
    {
        // A translator that shows the key and its parameters, to check what is asked for.
        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $id.([] === $parameters ? '' : json_encode($parameters));
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
        $this->format = new AdminFormat($translator, new MockClock('2026-10-21 18:30:00', 'Asia/Tehran'));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function urls(): iterable
    {
        yield 'secret path' => ['https://de1.example.net:2053/Xk9sEcReTpAtH42/', 'de1.example.net:2053'];
        yield 'credentials and query' => ['http://admin:pass@panel.example.net/path?token=abc#x', 'panel.example.net'];
        yield 'no port' => ['https://panel.example.net/secret', 'panel.example.net'];
        yield 'not a URL' => ['/only/a/path', null];
        yield 'empty' => [null, null];
    }

    #[DataProvider('urls')]
    public function testOnlyTheHostOfAUrlIsShown(?string $url, ?string $host): void
    {
        self::assertSame($host, AdminFormat::hostOf($url));
        $html = $this->format->host($url);
        foreach (['Xk9sEcReTpAtH42', 'pass', 'token', 'secret', '/path'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }

    public function testExpiryCountsCalendarDays(): void
    {
        $tz = new \DateTimeZone('Asia/Tehran');
        $expiry = fn (?string $at): string => html_entity_decode($this->format->expiry(null === $at ? null : new \DateTimeImmutable($at, $tz)));
        self::assertStringContainsString('format.days_left{"%count%":1}', $expiry('2026-10-22 08:00'), 'tomorrow morning is 1 day, not today');
        self::assertStringContainsString('format.days_left{"%count%":0}', $expiry('2026-10-21 23:00'));
        self::assertStringContainsString('format.expired_days_ago{"%count%":0}', $expiry('2026-10-21 09:00'));
        self::assertStringContainsString('format.expired_days_ago{"%count%":3}', $expiry('2026-10-18 20:00'));
        self::assertStringContainsString('format.days_left{"%count%":25}', $expiry('2026-11-15 01:00'));
        self::assertStringContainsString('format.unlimited', $expiry(null));
    }

    public function testTrafficMoneyAndEmptyValues(): void
    {
        $gb = 1073741824;
        self::assertStringContainsString('12.4 / 50 GB', $this->format->traffic((int) (12.4 * $gb), 50 * $gb));
        self::assertStringContainsString('aria-valuenow="25"', $this->format->traffic((int) (12.4 * $gb), 50 * $gb));
        self::assertStringContainsString('bg-danger', $this->format->traffic(49 * $gb, 50 * $gb));
        self::assertStringContainsString('format.unlimited', $this->format->traffic(80 * $gb, 0));
        self::assertStringContainsString('1,500,000</bdi> currency.IRT', $this->format->money(1_500_000));
        self::assertSame(AdminFormat::NONE, $this->format->money(null));
        self::assertSame(AdminFormat::NONE, $this->format->status(null));
        self::assertSame(AdminFormat::NONE, $this->format->copyable(''));
    }

    public function testValuesAreEscaped(): void
    {
        self::assertStringNotContainsString('<script>', $this->format->copyable('<script>alert(1)</script>'));
        self::assertStringNotContainsString('<b>', $this->format->status('<b>x</b>'));
    }
}
