<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

/**
 * What an admin's change to a service (ServiceAdministration) came to. Callers present it in their own words.
 */
enum ServiceAdminOutcome: string
{
    case DONE = 'done';
    case ALREADY_SUSPENDED = 'already_suspended';
    case ALREADY_ACTIVE = 'already_active';
    case ALREADY_DELETED = 'already_deleted';
    /** The action does not apply to a deleted service. */
    case NOT_ALLOWED = 'not_allowed';
    /** The panel refused or could not be reached; nothing changed here. */
    case PANEL_FAILED = 'panel_failed';
    /** The new traffic limit would not fit the byte counter. */
    case LIMIT_OVERFLOW = 'limit_overflow';
    /** Days / GB outside the accepted range. */
    case INVALID_AMOUNT = 'invalid_amount';

    public function isDone(): bool
    {
        return self::DONE === $this;
    }
}
