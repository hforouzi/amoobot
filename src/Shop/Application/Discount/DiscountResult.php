<?php

declare(strict_types=1);

namespace App\Shop\Application\Discount;

use App\Entity\DiscountCode;

final class DiscountResult
{
    public function __construct(
        public readonly bool $applied,
        public readonly string $message,
        public readonly ?DiscountCode $discountCode = null,
        public readonly int $amountBefore = 0,
        public readonly int $discountAmount = 0,
        public readonly int $amountAfter = 0,
    ) {
    }

    public static function failed(string $message, int $amountBefore = 0): self
    {
        return new self(false, $message, null, max(0, $amountBefore), 0, max(0, $amountBefore));
    }

    public static function applied(DiscountCode $discountCode, int $before, int $discount, int $after): self
    {
        return new self(true, 'ok', $discountCode, max(0, $before), max(0, $discount), max(0, $after));
    }
}
