<?php

declare(strict_types=1);

namespace App\Payment\Domain\Money;

final readonly class AmountMatch
{
    public function __construct(
        public AmountMatchStatus $status,
        public string $expectedAmount,
        public string $expectedCurrency,
        public ?string $actualAmount,
        public ?string $actualCurrency,
        /** Expected minus paid, in the expected currency; set only when less than expected was paid. */
        public ?string $shortfall = null,
        /** Underpayment tolerance (percent of the expected amount) the comparison used. */
        public string $tolerancePercent = '0',
    ) {
    }

    /**
     * Shortfall as a percentage of the expected amount, for display only (never for decisions).
     */
    public function shortfallPercent(): ?string
    {
        if (null === $this->shortfall || 0 === Decimal::compare($this->expectedAmount, '0')) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $this->shortfall / (float) $this->expectedAmount * 100, 2, '.', ''), '0'), '.');
    }

    public function describe(): string
    {
        $text = sprintf(
            '%s: expected %s %s, gateway reported %s %s',
            $this->status->value,
            $this->expectedAmount,
            $this->expectedCurrency,
            $this->actualAmount ?? '(none)',
            $this->actualCurrency ?? '(none)'
        );
        if (null !== $this->shortfall) {
            $text .= sprintf(
                '; shortfall %s %s (%s%%), tolerance %s%%',
                $this->shortfall,
                $this->expectedCurrency,
                $this->shortfallPercent() ?? '?',
                $this->tolerancePercent
            );
        }

        return $text;
    }
}
