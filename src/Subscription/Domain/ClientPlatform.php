<?php

declare(strict_types=1);

namespace App\Subscription\Domain;

/**
 * Operating systems a VPN client app / download is for. The subscription page guesses the visitor's from the
 * User-Agent (and the Telegram Mini App platform) to show matching apps first; a wrong guess only changes the order.
 */
enum ClientPlatform: string
{
    case ANDROID = 'android';
    case IOS = 'ios';
    case WINDOWS = 'windows';
    case MACOS = 'macos';
    case LINUX = 'linux';

    public function label(): string
    {
        return 'platform.'.$this->value;
    }

    /**
     * @return array<string, string> label translation key => value (form choices)
     */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $platform) {
            $choices[$platform->label()] = $platform->value;
        }

        return $choices;
    }

    /**
     * Platform values in display order, the given one first.
     *
     * @return list<string>
     */
    public static function ordered(?self $first = null): array
    {
        $order = array_map(static fn (self $p): string => $p->value, self::cases());

        return null === $first ? $order : array_values(array_unique([$first->value, ...$order]));
    }

    public static function fromUserAgent(?string $userAgent): ?self
    {
        $ua = strtolower((string) $userAgent);
        if ('' === $ua) {
            return null;
        }

        return match (true) {
            // Order matters: Android UAs contain "linux", iPadOS may claim "macintosh" (handled by the page script).
            str_contains($ua, 'android') => self::ANDROID,
            str_contains($ua, 'iphone'), str_contains($ua, 'ipad'), str_contains($ua, 'ipod') => self::IOS,
            str_contains($ua, 'windows') => self::WINDOWS,
            str_contains($ua, 'macintosh'), str_contains($ua, 'mac os x') => self::MACOS,
            str_contains($ua, 'linux'), str_contains($ua, 'x11') => self::LINUX,
            default => null,
        };
    }

    /** Telegram.WebApp.platform ("android", "ios", "tdesktop", "macos", "weba", ...); only the unambiguous ones. */
    public static function fromTelegramPlatform(?string $platform): ?self
    {
        return match (strtolower((string) $platform)) {
            'android', 'android_x' => self::ANDROID,
            'ios' => self::IOS,
            'macos' => self::MACOS,
            default => null,
        };
    }
}
