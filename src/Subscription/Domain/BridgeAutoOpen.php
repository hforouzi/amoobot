<?php

declare(strict_types=1);

namespace App\Subscription\Domain;

/**
 * The import bridge (/s/{token}/open/{appId}) tries the app's import link once by itself, so "add to app" in the Mini
 * App is one tap. This decides whether it may and what it opens; the page script does it once, never again on reload
 * or back (templates/subscription/bridge.html.twig), and the big button and the copy fallback stay for when nothing
 * opens (iOS asks "Open in …?", some browsers block it).
 *
 * No attempt for a HEAD request, a crawler or link-preview fetcher (or no User-Agent at all), or ?noauto=1.
 *
 * On Android, for an app whose Play Store page names its package, an intent:// URL with that package and the browser
 * fallback to the same page with ?noauto=1 (Chrome opens the store or the fallback when the app is missing, never a
 * loop); otherwise the plain import link.
 */
final class BridgeAutoOpen
{
    public const OPT_OUT = 'noauto';

    /** Search engines, link previews (Telegram, WhatsApp, Slack, ...) and command-line or headless clients. */
    private const CRAWLER = '/bot\b|crawl|spider|slurp|preview|facebookexternalhit|embedly|whatsapp|skypeuripreview|curl\/|wget\/|python|go-http-client|headless|lighthouse|httpclient|okhttp|java\//i';
    private const PACKAGE = '/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/D';
    private const SCHEME = '/^([A-Za-z][A-Za-z0-9+.-]*):\/\/(.+)$/sD';

    public static function allowed(string $method, ?string $userAgent, bool $optedOut): bool
    {
        return 'GET' === strtoupper($method) && !$optedOut && !self::isCrawler($userAgent);
    }

    public static function isCrawler(?string $userAgent): bool
    {
        $userAgent = trim((string) $userAgent);

        return '' === $userAgent || 1 === preg_match(self::CRAWLER, $userAgent);
    }

    /**
     * What the page opens: the plain import link, or on Android with a known package its intent:// form. A link of
     * http(s) or not of the scheme://… shape stays plain. The link's own fragment (#{name}) may stay: Android reads the
     * intent part from the last "#", and the fallback URL is percent-encoded, so it holds none.
     */
    public static function target(string $importUrl, ?string $androidPackage, bool $android, string $fallbackUrl): string
    {
        if (!$android || null === $androidPackage || 1 !== preg_match(self::PACKAGE, $androidPackage)
            || 1 !== preg_match(self::SCHEME, $importUrl, $m)
            || in_array(strtolower($m[1]), ['http', 'https', 'intent'], true)) {
            return $importUrl;
        }

        return sprintf('intent://%s#Intent;scheme=%s;package=%s;S.browser_fallback_url=%s;end', $m[2], strtolower($m[1]), $androidPackage, rawurlencode($fallbackUrl));
    }

    /** The package of a Google Play store link (https://play.google.com/store/apps/details?id=com.example.app), else null. */
    public static function androidPackage(?string $storeUrl): ?string
    {
        $parts = parse_url((string) $storeUrl);
        if (!is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '') || 'play.google.com' !== strtolower($parts['host'] ?? '')
            || '/store/apps/details' !== ($parts['path'] ?? '')) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        $id = $query['id'] ?? null;

        return is_string($id) && 1 === preg_match(self::PACKAGE, $id) ? $id : null;
    }
}
