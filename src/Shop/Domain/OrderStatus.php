<?php

declare(strict_types=1);

namespace App\Shop\Domain;

final class OrderStatus
{
    public const PENDING = 'pending';
    public const WAITING_PAYMENT = 'waiting_payment';
    public const PAID = 'paid';
    public const PROVISIONED = 'provisioned';
    public const CANCELLED = 'cancelled';
    public const FAILED = 'failed';

    public const ALL = [
        self::PENDING,
        self::WAITING_PAYMENT,
        self::PAID,
        self::PROVISIONED,
        self::CANCELLED,
        self::FAILED,
    ];
}
