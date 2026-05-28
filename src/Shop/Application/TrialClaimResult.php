<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Order;
use App\Entity\TrialClaim;
use App\Entity\VpnService;

final class TrialClaimResult
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ALREADY_CLAIMED = 'already_claimed';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_LIMIT_REACHED = 'limit_reached';
    public const STATUS_COOLDOWN = 'cooldown';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?TrialClaim $claim = null,
        public readonly ?Order $order = null,
        public readonly ?VpnService $vpnService = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return self::STATUS_SUCCESS === $this->status;
    }
}
