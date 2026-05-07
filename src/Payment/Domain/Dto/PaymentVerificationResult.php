<?php

declare(strict_types=1);

namespace App\Payment\Domain\Dto;

class PaymentVerificationResult
{
    public function __construct(
        public readonly bool $verified,
        public readonly ?string $message = null,
    ) {
    }
}
