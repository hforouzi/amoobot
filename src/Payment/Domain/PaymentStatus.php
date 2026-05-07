<?php

declare(strict_types=1);

namespace App\Payment\Domain;

final class PaymentStatus
{
    public const PENDING = 'pending';
    public const SUBMITTED = 'submitted';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';

    public const ALL = [
        self::PENDING,
        self::SUBMITTED,
        self::CONFIRMED,
        self::REJECTED,
    ];
}
