<?php

declare(strict_types=1);

namespace App\MiniApp\Checkout;

/**
 * A price as the server computes it, for the checkout page: base, global discount, discount code, total.
 */
final class CheckoutQuote
{
    public function __construct(
        public readonly int $baseAmount,
        public readonly int $globalDiscountAmount,
        public readonly int $amountBeforeCode,
        public readonly ?string $discountCode,
        public readonly bool $discountApplied,
        public readonly int $discountAmount,
        public readonly int $finalAmount,
        public readonly ?string $discountMessage,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'baseAmount' => $this->baseAmount,
            'globalDiscountAmount' => $this->globalDiscountAmount,
            'discountApplied' => $this->discountApplied,
            'discountAmount' => $this->discountAmount,
            'finalAmount' => $this->finalAmount,
            'discountMessage' => (string) $this->discountMessage,
        ];
    }
}
