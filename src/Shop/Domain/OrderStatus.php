<?php

declare(strict_types=1);

namespace App\Shop\Domain;

final class OrderStatus
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const WAITING_PAYMENT = 'waiting_payment';
    public const PAYMENT_PENDING = 'payment_pending';
    public const PAID = 'paid';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const PROVISIONED = 'provisioned';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';
    public const FAILED = 'failed';

    public const ALL = [
        self::DRAFT,
        self::PENDING,
        self::WAITING_PAYMENT,
        self::PAYMENT_PENDING,
        self::PAID,
        self::PROCESSING,
        self::COMPLETED,
        self::PROVISIONED,
        self::CANCELLED,
        self::EXPIRED,
        self::FAILED,
    ];
}
