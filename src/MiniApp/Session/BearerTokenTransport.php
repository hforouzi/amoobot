<?php

declare(strict_types=1);

namespace App\MiniApp\Session;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The token in the /app/auth JSON body, kept by the page in sessionStorage and sent back as "Authorization: Bearer".
 *
 * Chosen over a cookie because Telegram Web and Desktop show the Mini App in a cross-site iframe, where Safari (and
 * Chrome's third-party cookie restrictions) drop SameSite=None cookies; a header works the same in every client.
 * The browser never attaches it on its own, so a cross-site request cannot ride on the session.
 */
final class BearerTokenTransport implements SessionTokenTransport
{
    public function extract(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (1 !== preg_match('/^Bearer ([A-Za-z0-9_-]{1,128})$/', $header, $match)) {
            return null;
        }

        return $match[1];
    }

    public function deliver(IssuedSession $session, array $payload): JsonResponse
    {
        return new JsonResponse(['token' => $session->token, 'csrfToken' => $session->csrfToken, 'expiresAt' => $session->expiresAt->format(DATE_ATOM)] + $payload);
    }
}
