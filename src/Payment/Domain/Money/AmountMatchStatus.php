<?php

declare(strict_types=1);

namespace App\Payment\Domain\Money;

enum AmountMatchStatus: string
{
    case MATCH = 'match';
    case OVERPAID = 'overpaid';
    case UNDERPAID = 'underpaid';
    case CURRENCY_MISMATCH = 'currency_mismatch';
    case MISSING = 'missing';

    /**
     * Whether the core may confirm the payment. Everything else must go to manual review.
     */
    public function isAcceptable(): bool
    {
        return self::MATCH === $this || self::OVERPAID === $this;
    }
}
