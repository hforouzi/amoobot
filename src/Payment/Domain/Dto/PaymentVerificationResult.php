<?php

declare(strict_types=1);

namespace App\Payment\Domain\Dto;

class PaymentVerificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly bool $paid,
        public readonly ?string $transactionId = null,
        public readonly ?string $refId = null,
        public readonly ?string $message = null,
        public readonly ?array $rawResponse = null,
    ) {
    }
}
