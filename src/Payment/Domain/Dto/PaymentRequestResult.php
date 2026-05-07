<?php

declare(strict_types=1);

namespace App\Payment\Domain\Dto;

class PaymentRequestResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $reference = null,
        public readonly ?string $message = null,
    ) {
    }
}
