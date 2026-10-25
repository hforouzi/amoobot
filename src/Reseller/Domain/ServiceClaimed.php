<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/**
 * Dispatched after a claim was committed (docs/design/reseller-and-claim.md, 6.5): the integration point for later
 * features (referrals must never reward an invitee who arrived through a claim link). Ids only.
 */
final class ServiceClaimed
{
    public function __construct(
        public readonly int $claimId,
        public readonly int $serviceId,
        public readonly int $fromUserId,
        public readonly int $toUserId,
        public readonly ?int $resellerId,
    ) {
    }
}
