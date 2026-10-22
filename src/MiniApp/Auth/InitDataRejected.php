<?php

declare(strict_types=1);

namespace App\MiniApp\Auth;

/**
 * initData that failed validation. The message is only the reason code: never the data, a hash or a token.
 */
final class InitDataRejected extends \RuntimeException
{
    public const MALFORMED = 'malformed';
    public const MISSING_HASH = 'missing_hash';
    public const BAD_HASH = 'bad_hash';
    public const MISSING_AUTH_DATE = 'missing_auth_date';
    public const EXPIRED = 'expired';
    public const FUTURE = 'future';
    public const MISSING_USER = 'missing_user';
    /** No bot token is configured: nothing can be valid. */
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
