<?php

declare(strict_types=1);

namespace App\Payment\Domain;

final class PaymentStatus
{
    public const PENDING = 'pending';
    public const SUBMITTED = 'submitted';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    /** Gateway says paid, but amount/currency did not match (or were missing): an admin must decide. */
    public const NEEDS_REVIEW = 'needs_review';
    /** Payment verified, but provisioning/renewal failed; retryable without re-verifying. */
    public const PROVISIONING_FAILED = 'provisioning_failed';

    public const ALL = [
        self::PENDING,
        self::SUBMITTED,
        self::CONFIRMED,
        self::REJECTED,
        self::NEEDS_REVIEW,
        self::PROVISIONING_FAILED,
    ];

    /** States from which PaymentSettlementService may settle a payment. */
    public const SETTLEABLE = [
        self::PENDING,
        self::SUBMITTED,
    ];
}
