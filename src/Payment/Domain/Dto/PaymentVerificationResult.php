<?php

declare(strict_types=1);

namespace App\Payment\Domain\Dto;

class PaymentVerificationResult
{
    /**
     * @param string|null $amount   what the gateway itself says was paid, as a decimal string (e.g. "1250000" or "12.5")
     * @param string|null $currency currency of $amount as reported by the gateway (e.g. "IRR", "IRT", "USD")
     *
     * A paid result without both $amount and $currency is never confirmed automatically: the core
     * puts the payment into manual review (see App\Payment\Domain\Money\AmountMatcher).
     *
     * @param bool $failed the provider says this payment definitively failed/expired (core rejects it)
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $paid,
        public readonly ?string $transactionId = null,
        public readonly ?string $refId = null,
        public readonly ?string $message = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly bool $failed = false,
    ) {
    }
}
