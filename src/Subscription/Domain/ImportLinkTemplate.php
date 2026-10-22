<?php

declare(strict_types=1);

namespace App\Subscription\Domain;

/**
 * A client app's "add subscription" deep link, as a template:
 *   {url}         the subscription URL as is (path-style schemes: streisand://import/{url})
 *   {url_encoded} the URL percent-encoded (RFC 3986), for query parameters: v2rayng://install-sub?url={url_encoded}
 *   {url_base64}  the URL in base64 (Shadowrocket's sub://)
 *   {name}        the profile name, percent-encoded (fragment or query parameter)
 */
final class ImportLinkTemplate
{
    public const PLACEHOLDERS = ['{url}', '{url_encoded}', '{url_base64}', '{name}'];
    /** Schemes a browser would run or read locally instead of handing to an app. */
    private const FORBIDDEN_SCHEMES = ['javascript', 'data', 'vbscript', 'file', 'blob', 'about'];

    public static function build(?string $template, string $subscriptionUrl, string $name): ?string
    {
        $template = trim((string) $template);
        if ('' === $template || null !== self::problem($template)) {
            return null;
        }

        return strtr($template, [
            '{url_encoded}' => rawurlencode($subscriptionUrl),
            '{url_base64}' => base64_encode($subscriptionUrl),
            '{url}' => $subscriptionUrl,
            '{name}' => rawurlencode($name),
        ]);
    }

    /**
     * Why a template cannot be used (a translation key), or null when it is fine. No template at all is fine: the app
     * is offered with "install" and the copy-link fallback only.
     */
    public static function problem(?string $template): ?string
    {
        $template = trim((string) $template);
        if ('' === $template) {
            return null;
        }
        if (1 !== preg_match('/^([a-z][a-z0-9+.\-]*):/i', $template, $m)) {
            return 'client_app.template.no_scheme';
        }
        if (in_array(strtolower($m[1]), self::FORBIDDEN_SCHEMES, true)) {
            return 'client_app.template.forbidden_scheme';
        }
        if (1 === preg_match('/[\s"\'<>`\\\\]/', $template)) {
            return 'client_app.template.invalid_characters';
        }
        if (!str_contains($template, '{url}') && !str_contains($template, '{url_encoded}') && !str_contains($template, '{url_base64}')) {
            return 'client_app.template.no_url';
        }

        return null;
    }
}
