<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Entity\Payment;

/**
 * What a customer is told about a payment, read from its status only (never by asking a gateway). Shared by the
 * gateway return page (/payment/return/{token}) and the Mini App, so both say the same.
 */
final class PaymentDisplayState
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const REVIEW = 'review';
    public const PROVISION_FAILED = 'provision_failed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    /** States after which the payment will not change by itself (the Mini App stops polling). */
    public const FINAL = [self::PAID, self::PROVISION_FAILED, self::FAILED, self::CANCELLED, self::EXPIRED];

    /** The return page's states (pending / paid / review / provision_failed / cancelled / failed). */
    public static function forReturnPage(Payment $payment): string
    {
        return match ($payment->getStatus()) {
            PaymentStatus::CONFIRMED => self::PAID,
            PaymentStatus::NEEDS_REVIEW => self::REVIEW,
            PaymentStatus::PROVISIONING_FAILED => self::PROVISION_FAILED,
            // Cancelled/expired unpaid: not a failed payment. A later "paid" still goes to review.
            PaymentStatus::REJECTED => $payment->isRejectedUnpaid() ? self::CANCELLED : self::FAILED,
            default => self::PENDING,
        };
    }

    /**
     * The customer's view in the Mini App: as the return page, plus a sent card-to-card receipt waiting for an admin
     * (review) and an order that expired unpaid (expired, not cancelled).
     */
    public static function forCustomer(Payment $payment): string
    {
        if (PaymentStatus::SUBMITTED === $payment->getStatus()) {
            return self::REVIEW;
        }
        if ($payment->isRejectedByOrderExpiry()) {
            return self::EXPIRED;
        }

        return self::forReturnPage($payment);
    }

    public static function isFinal(string $state): bool
    {
        return in_array($state, self::FINAL, true);
    }
}
