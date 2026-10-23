<?php

declare(strict_types=1);

namespace App\Payment\Application\Settlement;

enum SettlementOutcome: string
{
    /** Payment confirmed and the order fulfilled (service created / renewed / topped up). */
    case CONFIRMED = 'confirmed';
    /** Nothing done: the payment was not in a state this action may change (idempotent repeat). */
    case ALREADY_PROCESSED = 'already_processed';
    /** Amount/currency check failed or the order was already fulfilled: an admin must decide. */
    case NEEDS_REVIEW = 'needs_review';
    /** Verified, but fulfilment failed; rolled back and marked for retry. */
    case PROVISIONING_FAILED = 'provisioning_failed';
    case REJECTED = 'rejected';
    /**
     * Admin approve/retry refused: the order is already fulfilled by another payment, so fulfilling
     * again would provision twice. Nothing changed; the admin should reject this payment (and record
     * a refund) instead.
     */
    case ORDER_ALREADY_FULFILLED = 'order_already_fulfilled';
    /**
     * A payment of method "wallet" was not confirmed (settlement, admin approve or retry): its wallet debit was given
     * back or never written, so confirming would deliver the order without payment. Nothing changed; the admin should
     * reject this payment.
     */
    case WALLET_DEBIT_MISSING = 'wallet_debit_missing';
    /** Infrastructure error before any state change (e.g. lock timeout). Safe to retry the request. */
    case ERROR = 'error';
}
