<?php

declare(strict_types=1);

namespace App\Payment\Domain\Money;

enum AmountMatchStatus: string
{
    case MATCH = 'match';
    case OVERPAID = 'overpaid';
    /** Less than expected, but the shortfall is within the gateway's underpayment tolerance. */
    case UNDERPAID_WITHIN_TOLERANCE = 'underpaid_within_tolerance';
    case UNDERPAID = 'underpaid';
    case CURRENCY_MISMATCH = 'currency_mismatch';
    case MISSING = 'missing';

    /**
     * Whether the core may confirm the payment. Everything else must go to manual review.
     */
    public function isAcceptable(): bool
    {
        return self::MATCH === $this || self::OVERPAID === $this || self::UNDERPAID_WITHIN_TOLERANCE === $this;
    }
}
