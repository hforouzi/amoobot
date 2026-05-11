<?php

declare(strict_types=1);

namespace App\Shop\Domain;

final class OrderDraftStatus
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    public const ALL = [
        self::PENDING,
        self::CONFIRMED,
        self::CANCELLED,
        self::EXPIRED,
    ];
}

