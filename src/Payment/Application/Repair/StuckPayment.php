<?php

declare(strict_types=1);

namespace App\Payment\Application\Repair;

use App\Entity\Payment;

final readonly class StuckPayment
{
    public function __construct(
        public Payment $payment,
        public StuckPaymentCategory $category,
        /** What was found (panel snapshot for renewals); stored as the provisioning error when applied. */
        public string $detail,
    ) {
    }
}
