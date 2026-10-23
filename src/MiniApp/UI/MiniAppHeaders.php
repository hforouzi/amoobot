<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use Symfony\Component\HttpFoundation\Response;

/**
 * Headers of every Mini App page and fragment: private and never cached, not indexed, no Referer, and a CSP that
 * allows only this site's own files (scripts, styles, the self-hosted font) plus Telegram's official telegram-web-app.js.
 */
final class MiniAppHeaders
{
    public const CSP = "default-src 'none'; script-src 'self' https://telegram.org; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors https://web.telegram.org";

    public static function apply(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', self::CSP);

        return $response;
    }
}
