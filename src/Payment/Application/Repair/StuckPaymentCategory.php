<?php

declare(strict_types=1);

namespace App\Payment\Application\Repair;

enum StuckPaymentCategory: string
{
    /** New service order, payment confirmed, order never provisioned, no service row. Safe to retry. */
    case NEW_SERVICE_NOT_PROVISIONED = 'new_service_not_provisioned';
    /** Order says provisioned but no service row exists (failed write, or an admin deleted the service). */
    case NEW_SERVICE_SERVICE_MISSING = 'new_service_service_missing';
    /** Renewal / add-traffic not applied, and the panel still shows the pre-renewal expiry/traffic. */
    case RENEWAL_NOT_APPLIED = 'renewal_not_applied';
    /** Renewal / add-traffic not recorded, but the panel is ahead of the database: it may have been applied. */
    case RENEWAL_MAYBE_APPLIED_ON_PANEL = 'renewal_maybe_applied_on_panel';
    /** Renewal / add-traffic not recorded and the panel state could not be compared. */
    case RENEWAL_UNVERIFIABLE = 'renewal_unverifiable';

    /**
     * Whether --apply may move the payment to PROVISIONING_FAILED (so an admin can retry it).
     */
    public function isRetryable(bool $includeServiceMissing): bool
    {
        return match ($this) {
            self::NEW_SERVICE_NOT_PROVISIONED, self::RENEWAL_NOT_APPLIED => true,
            self::NEW_SERVICE_SERVICE_MISSING => $includeServiceMissing,
            self::RENEWAL_MAYBE_APPLIED_ON_PANEL, self::RENEWAL_UNVERIFIABLE => false,
        };
    }
}
