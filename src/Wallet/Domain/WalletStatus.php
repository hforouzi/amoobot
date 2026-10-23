<?php

declare(strict_types=1);

namespace App\Wallet\Domain;

final class WalletStatus
{
    public const ACTIVE = 'active';
    /** No spending (PURCHASE); money still comes in (paid top-ups, rewards) and admins can still correct. */
    public const FROZEN = 'frozen';

    public const ALL = [self::ACTIVE, self::FROZEN];
}
