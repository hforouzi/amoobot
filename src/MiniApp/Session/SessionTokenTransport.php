<?php

declare(strict_types=1);

namespace App\MiniApp\Session;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * How the session token travels between the Mini App and the server: the single seam to change it (services.yaml
 * alias). Today BearerTokenTransport (Authorization header); a cookie transport would implement the same two methods.
 */
interface SessionTokenTransport
{
    /** The token the client sent, if any. */
    public function extract(Request $request): ?string;

    /**
     * The /app/auth response that hands the new session to the client.
     *
     * @param array<string, mixed> $payload what the client gets besides the token
     */
    public function deliver(IssuedSession $session, array $payload): JsonResponse;
}
