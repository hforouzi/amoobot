<?php

declare(strict_types=1);

namespace App\Payment\Domain\Dto;

class PaymentRequestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $paymentUrl = null,
        public readonly ?string $transactionId = null,
        public readonly ?string $authority = null,
        public readonly ?string $message = null,
        public readonly ?array $rawResponse = null,
    ) {
    }
}
