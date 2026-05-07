<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Entity\VpnService;

class PaymentApprovalResult
{
    public function __construct(
        public readonly bool $processed,
        public readonly bool $alreadyProcessed,
        public readonly string $message,
        public readonly ?VpnService $vpnService = null,
    ) {
    }

    public static function processed(string $message, ?VpnService $vpnService = null): self
    {
        return new self(true, false, $message, $vpnService);
    }

    public static function alreadyProcessed(string $message, ?VpnService $vpnService = null): self
    {
        return new self(false, true, $message, $vpnService);
    }
}
