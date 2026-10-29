<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\RotatedCredentials;

/**
 * Drivers that can give a client new connection credentials (a new UUID and subscription id) on the panel, keeping its
 * email, traffic, expiry and status: the old config links and subscription URL stop working. Used by the owner's
 * "Reset my connection links" after a claim (docs/design/reseller-and-claim.md, D4); a driver without it cannot offer it.
 *
 * The caller chooses the new credentials, so that after a failure it can ask the panel (currentCredentials()) whether
 * they were applied: a timeout or a failed read-back after the panel already changed the client must not leave the
 * database with the old ones.
 */
interface CredentialRotationDriverInterface
{
    /** Whether this service's client can be rotated (its protocol authenticates by UUID). */
    public function canRotateCredentials(string $remoteId, ?VpnPanel $panel = null): bool;

    /**
     * Gives the client exactly these credentials (also used to put them back when a concurrent write reverted them).
     *
     * @param string $newUuid  an RFC 4122 UUID
     * @param string $newSubId the new subscription id
     *
     * @throws \RuntimeException the panel refused, or the outcome could not be confirmed (it may have changed:
     *                           currentCredentials() tells)
     */
    public function rotateCredentials(string $remoteId, string $newUuid, string $newSubId, ?VpnPanel $panel = null): RotatedCredentials;

    /**
     * The client's credentials on the panel now (found by its email, so a stale UUID in $remoteId does not matter), or
     * null when the panel has no such client.
     *
     * @throws \RuntimeException the panel cannot answer
     */
    public function currentCredentials(string $remoteId, ?VpnPanel $panel = null): ?RotatedCredentials;
}
