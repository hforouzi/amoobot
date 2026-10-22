<?php

declare(strict_types=1);

namespace App\Shop\Domain;

final class OrderType
{
    public const NEW_SERVICE = 'new_service';
    public const RENEWAL = 'renewal';
    public const ADD_TRAFFIC = 'add_traffic';
    public const TRIAL = 'trial';
    /** Money paid into the customer's wallet: no plan, nothing provisioned (PaymentSettlementService credits it). */
    public const WALLET_TOPUP = 'wallet_topup';

    public const ALL = [
        self::NEW_SERVICE,
        self::RENEWAL,
        self::ADD_TRAFFIC,
        self::TRIAL,
        self::WALLET_TOPUP,
    ];
}
