<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

/**
 * Whether an address can be handed to a customer: a phone on the internet must be able to reach it. The panel is often
 * connected through 127.0.0.1 or a private address, which is fine for the shop but useless in a client app.
 */
final class PublicUrlPolicy
{
    private const RESERVED_SUFFIXES = ['.local', '.internal', '.localhost', '.lan', '.home.arpa', '.localdomain'];

    /** An http(s) URL with a public host (no loopback, private, link-local or reserved range, no local-only name). */
    public static function isPubliclyUsableUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ('' === $url) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return self::isPublicHost((string) ($parts['host'] ?? ''));
    }

    /**
     * A config link (vless://, vmess://, trojan://, ss://, ... or an http(s) URL) whose server address is public.
     */
    public static function isPubliclyUsableLink(?string $link): bool
    {
        $link = trim((string) $link);
        if ('' === $link) {
            return false;
        }

        $host = self::linkHost($link);

        return null !== $host && self::isPublicHost($host);
    }

    /** The server address a config link points to, or null when it cannot be read. */
    public static function linkHost(string $link): ?string
    {
        $link = trim($link);
        if (str_starts_with(strtolower($link), 'vmess://')) {
            $payload = substr($link, 8);
            $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
            $data = false !== $decoded ? json_decode($decoded, true) : null;
            if (!is_array($data) || !is_scalar($data['add'] ?? null)) {
                return null;
            }
            $host = trim((string) $data['add']);

            return '' !== $host ? $host : null;
        }

        $parts = parse_url($link);
        if (!is_array($parts) || !isset($parts['host']) || '' === $parts['host']) {
            return null;
        }

        return $parts['host'];
    }

    public static function isPublicHost(string $host): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]."));
        if ('' === $host) {
            return false;
        }

        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                && !self::isCarrierGradeNat($host);
        }

        if ('localhost' === $host || !str_contains($host, '.')) {
            return false;
        }
        foreach (self::RESERVED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return 1 === preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host);
    }

    /** 100.64.0.0/10, shared address space; PHP's range flags do not cover it. */
    private static function isCarrierGradeNat(string $ip): bool
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $long = ip2long($ip);

        return false !== $long && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000);
    }
}
