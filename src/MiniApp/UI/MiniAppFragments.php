<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

/**
 * Renders a Mini App fragment (a section, the navigation) in the request's language with the Mini App headers.
 * public/miniapp/app.js fetches these with the session token and puts them into the shell.
 */
final class MiniAppFragments
{
    public function __construct(
        private readonly Environment $twig,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function render(Request $request, string $template, array $parameters = [], int $status = Response::HTTP_OK): Response
    {
        $locale = MiniAppLocale::fromRequest($request);
        $this->localeSwitcher->setLocale($locale);
        $request->setLocale($locale);

        return MiniAppHeaders::apply(new Response($this->twig->render($template, $parameters + ['lang' => $locale]), $status));
    }
}
