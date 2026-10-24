<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/**
 * What the reseller confirmed: the amount he was shown and the one-time nonce of that confirmation (ResellerConfirmations).
 * The amount is only checked against the price at tap time, never charged as such.
 */
final class ResellerConfirmation
{
    public function __construct(
        public readonly int $amount,
        public readonly string $nonce,
    ) {
    }
}
