<?php

declare(strict_types=1);

namespace App\Payment\Application\Reconcile;

/**
 * What one reconcile attempt did to one payment. Only SETTLED, NEEDS_REVIEW and PROVISIONING_FAILED mean its state
 * changed, and only through PaymentSettlementService.
 */
enum WinapayReconcileOutcome: string
{
    /** Winapay answered 100 with the exact amount, and the payment is confirmed and fulfilled. */
    case SETTLED = 'settled';
    /** Not pending any more: the callback, the return page or an admin got there first. Nothing changed. */
    case ALREADY_SETTLED = 'already_settled';
    /** Winapay says it was not paid (or not yet). Still pending. */
    case NOT_PAID = 'not_paid';
    /** Network error, timeout, an error code or no usable answer. Still pending; the next run asks again. */
    case VERIFY_FAILED = 'verify_failed';
    /** Winapay answered 100 with another amount. Still pending; an admin must look at it. */
    case AMOUNT_MISMATCH = 'amount_mismatch';
    /** Winapay says paid but gives no amount (101 already verified, or a 100 without proof). Still pending; an admin must look at it. */
    case PAID_WITHOUT_PROOF = 'paid_without_proof';
    /** Valid proof, but settlement parked the payment for an admin (a duplicate, or another path parked it first). */
    case NEEDS_REVIEW = 'needs_review';
    /** Valid proof, but fulfilment failed; the payment is in the admin's retry list. */
    case PROVISIONING_FAILED = 'provisioning_failed';
    /** The driver could not be resolved or the settlement failed. Still pending, or as settlement left it. */
    case ERROR = 'error';
}
