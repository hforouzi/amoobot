<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\RotatedCredentials;

/**
 * Drivers that can give a client new connection credentials (a new UUID and subscription id) on the panel, keeping its
 * email, traffic, expiry and status: the old config links and subscription URL stop working. Used by the owner's
 * "Reset my connection links" after a claim (docs/design/reseller-and-claim.md, D4); a driver without it cannot offer it.
 */
interface CredentialRotationDriverInterface
{
    /** Whether this service's client can be rotated (its protocol authenticates by UUID). */
    public function canRotateCredentials(string $remoteId, ?VpnPanel $panel = null): bool;

    /**
     * @throws \RuntimeException the panel refused or could not confirm it (nothing is known to have changed)
     */
    public function rotateCredentials(string $remoteId, ?VpnPanel $panel = null): RotatedCredentials;
}
