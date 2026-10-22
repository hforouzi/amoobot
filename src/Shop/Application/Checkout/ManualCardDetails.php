<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

/**
 * Where to transfer a card-to-card payment.
 */
final class ManualCardDetails
{
    public function __construct(
        public readonly ?string $cardNumber,
        public readonly ?string $cardHolder,
        public readonly ?string $instructions,
    ) {
    }
}
