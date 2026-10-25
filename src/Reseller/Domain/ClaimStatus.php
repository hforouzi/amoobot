<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/**
 * A claim link's status (service_claim.status). Only an open link can be used; every other status is final.
 */
final class ClaimStatus
{
    public const OPEN = 'open';
    public const CLAIMED = 'claimed';
    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';

    public const ALL = [self::OPEN, self::CLAIMED, self::REVOKED, self::EXPIRED];
}
