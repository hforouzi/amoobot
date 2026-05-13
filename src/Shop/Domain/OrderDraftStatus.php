<?php

declare(strict_types=1);

namespace App\Shop\Domain;

final class OrderDraftStatus
{
    public const PENDING = 'pending';
    public const AWAITING_USERNAME = 'awaiting_username';
    public const AWAITING_TRAFFIC = 'awaiting_traffic';
    public const AWAITING_DURATION = 'awaiting_duration';
    public const AWAITING_DISCOUNT_CHOICE = 'awaiting_discount_choice';
    public const AWAITING_DISCOUNT_CODE = 'awaiting_discount_code';
    public const AWAITING_PAYMENT_METHOD = 'awaiting_payment_method';
    public const CONFIRMED = 'confirmed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    public const ALL = [
        self::PENDING,
        self::AWAITING_USERNAME,
        self::AWAITING_TRAFFIC,
        self::AWAITING_DURATION,
        self::AWAITING_DISCOUNT_CHOICE,
        self::AWAITING_DISCOUNT_CODE,
        self::AWAITING_PAYMENT_METHOD,
        self::CONFIRMED,
        self::CANCELLED,
        self::EXPIRED,
    ];
}
