<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Subscription\UI\SubscriptionFormat;
use PHPUnit\Framework\Assert;

/**
 * Golden files of what a customer sees about a VPN service (bot messages, notifications, Mini App fragments and JSON,
 * the subscription page and its import bridge), written before PM1d moved customerView/customerActions into the
 * product module, so that PR can prove its output byte-identical (docs/design/product-modules.md, section 9).
 *
 * Files live under tests/<area>/__snapshots__/customer-view/. Record or update them with UPDATE_BOT_SNAPSHOTS=1 (the
 * switch BotTranscript uses). Volatile values are replaced before comparing:
 * - dates: services are built at fixed offsets from $now. "Y-m-d H:i:s" becomes <datetime now+20d+12h> (rounded to
 *   the hour, so the seconds a test takes do not matter). A calendar day alone ("Y-m-d", and the Persian calendar
 *   form of SubscriptionFormat::date()) depends on the time of day the test runs, so it is named after the expiry it
 *   shows: <date of now+20d+12h>, <jdate of now+20d+12h>;
 * - opaque values (tokens, uuids, panel usernames) by value ($replace);
 * - service ids only where they appear as ids (paths, data attributes, "#12", "VPN 12"), never a bare number.
 */
final class CustomerViewSnapshot
{
    /**
     * @param array<string, string>    $replace    exact opaque value => placeholder
     * @param array<int, string>       $serviceIds service id => placeholder
     * @param list<\DateTimeImmutable> $instants   the expiries that may be shown as a calendar day
     */
    public static function normalize(string $text, \DateTimeImmutable $now, array $replace = [], array $serviceIds = [], array $instants = []): string
    {
        unset($replace['']);
        uksort($replace, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $text = strtr($text, $replace);
        $text = self::dates($text, $now, $instants);

        foreach ($serviceIds as $id => $name) {
            // After "/", "#", "=", a quote, ":", "VPN " (also URL-encoded: a client app's profile name) or "سرویس ", and
            // not followed by a letter or digit (so a CSS colour such as #2563eb is never taken for service 2563).
            $text = (string) preg_replace('/(?<=[\/#=":]|VPN |VPN%20|\x{0633}\x{0631}\x{0648}\x{06CC}\x{0633} )'.$id.'(?![0-9A-Za-z])/u', $name, $text);
        }

        return str_replace("\r\n", "\n", $text);
    }

    /**
     * @param list<\DateTimeImmutable> $instants
     */
    public static function dates(string $text, \DateTimeImmutable $now, array $instants = []): string
    {
        $text = (string) preg_replace_callback('/\b(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\b/', static fn (array $m): string => '<datetime '.self::offset(new \DateTimeImmutable($m[1].' '.$m[2], $now->getTimezone()), $now).'>', $text);

        $labels = [];
        foreach ($instants as $instant) {
            $labels[$instant->setTimezone($now->getTimezone())->format('Y-m-d')] = 'of '.self::offset($instant, $now);
        }
        // A day no instant explains: its distance from today (may depend on the time of day; no snapshot has one).
        preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $text, $matches);
        $today = $now->setTime(0, 0);
        foreach (array_unique($matches[0]) as $ymd) {
            $day = new \DateTimeImmutable($ymd, $now->getTimezone());
            $labels[$ymd] ??= sprintf('today%+d', (int) round(($day->getTimestamp() - $today->getTimestamp()) / 86400));
        }
        foreach ($labels as $ymd => $label) {
            // The Persian calendar form first: it never contains the Y-m-d form.
            $jalali = SubscriptionFormat::date(new \DateTimeImmutable($ymd.' 12:00:00', $now->getTimezone()), 'fa');
            if ($jalali !== $ymd) {
                $text = str_replace($jalali, '<jdate '.$label.'>', $text);
            }
            $text = (string) preg_replace('/\b'.preg_quote((string) $ymd, '/').'\b/', '<date '.$label.'>', $text);
        }

        return $text;
    }

    public static function assertMatches(string $area, string $name, string $actual): void
    {
        $file = dirname(__DIR__).'/'.$area.'/__snapshots__/customer-view/'.$name;
        if ('1' === getenv('UPDATE_BOT_SNAPSHOTS') || !is_file($file)) {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, $actual);
        }
        // Line endings depend on the checkout (core.autocrlf), not on the code.
        Assert::assertSame(str_replace("\r\n", "\n", (string) file_get_contents($file)), $actual, 'differs from tests/'.$area.'/__snapshots__/customer-view/'.$name);
    }

    /**
     * @param list<string>       $lines      BotTranscript::lines()
     * @param array<int, string> $serviceIds
     */
    public static function assertTranscript(string $name, array $lines, \DateTimeImmutable $now, array $serviceIds = []): void
    {
        $text = implode("\n\n", array_map(static fn (string $line): string => self::normalize($line, $now, [], $serviceIds), $lines))."\n";
        self::assertMatches('Bot', $name.'.txt', $text);
    }

    /** "now+20d+12h": the distance from $now, rounded to the hour. */
    private static function offset(\DateTimeImmutable $at, \DateTimeImmutable $now): string
    {
        $hours = (int) round(($at->getTimestamp() - $now->getTimestamp()) / 3600);
        $days = intdiv($hours, 24);

        return sprintf('now%+dd%+dh', $days, $hours - 24 * $days);
    }
}
