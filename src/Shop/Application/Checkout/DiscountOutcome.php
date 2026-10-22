<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

/**
 * The result of a discount code on an order or draft: applied (code, amount off, new total) or invalid (why, total
 * unchanged).
 */
final class DiscountOutcome
{
    private function __construct(
        public readonly bool $applied,
        public readonly ?string $code,
        public readonly int $discountAmount,
        public readonly int $finalAmount,
        public readonly string $reason,
    ) {
    }

    public static function applied(string $code, int $discountAmount, int $finalAmount): self
    {
        return new self(true, $code, $discountAmount, $finalAmount, '');
    }

    public static function invalid(string $reason, int $finalAmount): self
    {
        return new self(false, null, 0, $finalAmount, $reason);
    }
}
