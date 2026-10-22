<?php

declare(strict_types=1);

namespace App\MiniApp\Session;

/**
 * A just-opened session: the raw token exists only here and in the response to /app/auth.
 */
final class IssuedSession
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $token,
        public readonly string $csrfToken,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
