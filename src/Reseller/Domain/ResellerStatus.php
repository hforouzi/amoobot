<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/** A reseller profile's status. Only an active profile (with reseller.enabled on) may buy at wholesale. */
final class ResellerStatus
{
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';

    public const ALL = [self::ACTIVE, self::DISABLED];
}
