<?php

declare(strict_types=1);

namespace App\Admin\UI;

use App\Admin\Routing\LegacyAdminRoutes;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Old admin URL -> its new place (LegacyAdminRoutes), keeping the query string. GET/HEAD get a 302; anything else
 * a 307, so a form still open in an old tab posts to the new page.
 */
final class LegacyAdminRedirectController
{
    public function __invoke(Request $request, string $rest = ''): RedirectResponse
    {
        $redirect = LegacyAdminRoutes::REDIRECTS[(string) $request->attributes->get('_route')] ?? throw new NotFoundHttpException();

        $path = $redirect['to'];
        $query = $request->query->all();
        $rest = trim($rest, '/');
        if ('' === $rest) {
            $query = ($redirect['query'] ?? []) + $query;
        } else {
            $path .= '/'.$rest;
        }

        return new RedirectResponse(
            $request->getBasePath().$path.([] === $query ? '' : '?'.http_build_query($query)),
            $request->isMethodSafe() ? RedirectResponse::HTTP_FOUND : RedirectResponse::HTTP_TEMPORARY_REDIRECT,
        );
    }
}
