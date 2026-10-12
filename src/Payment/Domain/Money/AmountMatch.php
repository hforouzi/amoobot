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
    ) {
    }

    public function describe(): string
    {
        return sprintf(
            '%s: expected %s %s, gateway reported %s %s',
            $this->status->value,
            $this->expectedAmount,
            $this->expectedCurrency,
            $this->actualAmount ?? '(none)',
            $this->actualCurrency ?? '(none)'
        );
    }
}
