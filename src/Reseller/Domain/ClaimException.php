<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/**
 * Why a claim-link action was refused, as a stable code; nothing was written when it is thrown.
 *
 * For the person using a link, every "this link is not usable" case (unknown, malformed, expired, revoked, claimed by
 * someone else, owner changed, service deleted, feature off) is the one code UNAVAILABLE, so the answer is no oracle;
 * the real reason is in $reason (server log, the creator's list). The other codes are safe to show: the user already
 * holds a valid link (busy, own service) or it is about him (rate limited).
 */
final class ClaimException extends \RuntimeException
{
    /** The generic "this link cannot be used" (see the class comment). */
    public const UNAVAILABLE = 'claim_unavailable';
    /** Too many link previews / claims; try later. */
    public const RATE_LIMITED = 'rate_limited';
    /** An order of the service is being paid or provisioned; try again in a minute. */
    public const BUSY = 'service_busy';
    /** The link's creator tried to claim his own service. */
    public const OWN_SERVICE = 'own_service';
    /** He already claimed this link: the service is in his account. */
    public const ALREADY_YOURS = 'already_yours';

    // Creator side (reseller bot, admin pages).
    public const FEATURE_OFF = 'claims_disabled';
    public const RESELLER_UNAVAILABLE = 'reseller_unavailable';
    public const NOT_CLAIMABLE = 'not_claimable';
    public const NOT_FOUND = 'not_found';
    public const REASON_REQUIRED = 'reason_required';
    public const NOT_RETURNABLE = 'not_returnable';
    public const RESET_UNSUPPORTED = 'reset_unsupported';
    public const RESET_FAILED = 'reset_failed';

    public function __construct(
        public readonly string $error,
        /** The internal reason (logged; never shown to a claimer). */
        public readonly string $reason = '',
    ) {
        parent::__construct('' === $reason ? $error : $error.': '.$reason);
    }
}
