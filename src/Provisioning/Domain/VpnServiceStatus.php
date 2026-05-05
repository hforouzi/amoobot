<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

final class VpnServiceStatus
{
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const EXPIRED = 'expired';
    public const DELETED = 'deleted';

    public const ALL = [
        self::ACTIVE,
        self::SUSPENDED,
        self::EXPIRED,
        self::DELETED,
    ];
}
