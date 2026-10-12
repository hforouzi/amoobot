<?php

declare(strict_types=1);

namespace App\Payment\Application\Settlement;

use App\Payment\Domain\Dto\PaymentVerificationResult;

/**
 * Proof that a payment was made, as handed to PaymentSettlementService::settle().
 *
 * Either a gateway verification (amount + currency are checked strictly by the core) or an admin's
 * decision (manual card receipt / review), which is the verification itself and skips the amount check.
 */
final readonly class VerifiedPayment
{
    private function __construct(
        public bool $byAdmin,
        public ?string $amount,
        public ?string $currency,
        public ?string $transactionId,
        public ?string $refId,
        public ?int $adminId,
        public ?string $adminTelegramId,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the gateway did not report the payment as paid
     */
    public static function fromGateway(PaymentVerificationResult $verification): self
    {
        if (!$verification->success || !$verification->paid) {
            throw new \InvalidArgumentException('Only a successful, paid verification can be settled.');
        }

        return new self(false, $verification->amount, $verification->currency, $verification->transactionId, $verification->refId, null, null);
    }

    public static function byAdmin(?int $adminId = null, ?string $adminTelegramId = null): self
    {
        return new self(true, null, null, null, null, $adminId, $adminTelegramId);
    }
}
