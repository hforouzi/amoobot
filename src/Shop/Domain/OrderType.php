<?php

declare(strict_types=1);

namespace App\Shop\Domain;

final class OrderType
{
    public const NEW_SERVICE = 'new_service';
    public const RENEWAL = 'renewal';
    public const ADD_TRAFFIC = 'add_traffic';

    public const ALL = [
        self::NEW_SERVICE,
        self::RENEWAL,
        self::ADD_TRAFFIC,
    ];
}
