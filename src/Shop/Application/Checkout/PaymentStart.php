<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\Payment;

/**
 * What starting a payment produced: which kind of screen the customer needs next (a gateway page to open, a crypto
 * payment, card-to-card instructions) and whether the gateway accepted the request.
 */
final class PaymentStart
{
    /** Zibal, custom API and plugin gateways: a payment page (paymentUrl). */
    public const KIND_ONLINE = 'online';
    /** NOWPayments: an invoice page, or an address and amount to pay. */
    public const KIND_CRYPTO = 'crypto';
    /** Card-to-card: details to show, then a receipt for an admin. */
    public const KIND_MANUAL_CARD = 'manual_card';
    /** The order's plan was switched off: no payment was started. */
    public const KIND_PLAN_INACTIVE = 'plan_inactive';
    /**
     * The order has a wallet payment that is debited but not settled yet: no gateway payment was started. The customer
     * pays from the wallet again (the same debit is reused) or cancels the order (the debit is given back).
     */
    public const KIND_WALLET_PAYMENT_PENDING = 'wallet_payment_pending';

    public function __construct(
        public readonly string $kind,
        public readonly ?Payment $payment,
        public readonly bool $success,
        public readonly ?string $message,
        public readonly int $amount,
    ) {
    }

    /** An online/crypto payment that can be continued at the gateway (a manual card always can). */
    public function isReady(): bool
    {
        return match ($this->kind) {
            self::KIND_ONLINE => $this->success && null !== $this->payment?->getPaymentUrl(),
            self::KIND_CRYPTO => $this->success,
            self::KIND_MANUAL_CARD => null !== $this->payment,
            default => false,
        };
    }
}
