<?php

declare(strict_types=1);

namespace App\Payment\Domain;

final class PaymentGatewayType
{
    public const MANUAL_CARD = 'manual_card';
    public const ZIBAL = 'zibal';

    public const ALL = [
        self::MANUAL_CARD,
        self::ZIBAL,
    ];
}

