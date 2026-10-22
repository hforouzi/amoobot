<?php

declare(strict_types=1);

namespace App\MiniApp\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** A state-changing Mini App request without this session's CSRF token (answered 403, not 401). */
final class CsrfRejected extends AuthenticationException
{
}
