<?php

declare(strict_types=1);

namespace App\Payment\Application\Reconcile;

/**
 * What one Tetraminator inquiry did to one payment. Only SETTLED, NEEDS_REVIEW and PROVISIONING_FAILED mean its state
 * changed, and only through PaymentSettlementService. Nothing here ever expires or fails a payment.
 */
enum TetraminatorReconcileOutcome: string
{
    /** Not asked: no such payment, another gateway, no stored pay_id, or the payment no longer accepts a verification. */
    case SKIPPED = 'skipped';
    /** Tetraminator confirmed the exact amount, and the payment is confirmed and fulfilled. */
    case SETTLED = 'settled';
    /** Another path (webhook, timer, return page, check button) settled it first. Nothing changed. */
    case ALREADY_SETTLED = 'already_settled';
    /** A well-formed "not paid" (or not yet). Unchanged. */
    case NOT_PAID = 'not_paid';
    /** Network error, timeout, 4xx/5xx or no usable answer. Unchanged; the next trigger asks again. */
    case VERIFY_FAILED = 'verify_failed';
    /** 401 or 403: the key or the store needs an admin. Unchanged; a batch stops here (design decision 4). */
    case ACCOUNT_REFUSED = 'account_refused';
    /** Paid, but settlement parked it for an admin: amount or pay_id mismatch, late money, a duplicate payment. */
    case NEEDS_REVIEW = 'needs_review';
    /** Paid, but fulfilment failed; the payment is in the admin's retry list. */
    case PROVISIONING_FAILED = 'provisioning_failed';
    /** The driver could not be resolved, or the settlement failed. Unchanged, or as settlement left it. */
    case ERROR = 'error';
}
