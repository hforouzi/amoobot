<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use Symfony\Component\HttpFoundation\Request;

/**
 * Persian by default; ?lang=en switches (the page passes it on to every section it loads), like the subscription page.
 */
final class MiniAppLocale
{
    public static function fromRequest(Request $request): string
    {
        return 'en' === $request->query->get('lang') ? 'en' : 'fa';
    }
}
