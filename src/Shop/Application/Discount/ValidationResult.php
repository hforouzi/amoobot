<?php

declare(strict_types=1);

namespace App\Shop\Application\Discount;

use App\Entity\DiscountCode;

final class ValidationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly string $message,
        public readonly ?DiscountCode $discountCode = null,
        public readonly int $discountAmount = 0,
        public readonly int $finalAmount = 0,
    ) {
    }

    public static function invalid(string $message): self
    {
        return new self(false, $message);
    }

    public static function valid(DiscountCode $discountCode, int $discountAmount, int $finalAmount): self
    {
        return new self(true, 'ok', $discountCode, max(0, $discountAmount), max(0, $finalAmount));
    }
}
