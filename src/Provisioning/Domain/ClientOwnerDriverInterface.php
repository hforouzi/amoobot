<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnPanel;

/**
 * Drivers that can record on the panel who owns a client (3x-ui: tgId and comment), leaving its credentials, limits,
 * expiry and status as they are. Used after a service changes owner (claim links); a driver without it is skipped and
 * the database alone says who the owner is.
 */
interface ClientOwnerDriverInterface
{
    /**
     * @param int    $telegramId the owner's Telegram user id (0: none)
     * @param string $comment    the client's comment on the panel
     */
    public function updateClientOwner(string $remoteId, int $telegramId, string $comment, ?VpnPanel $panel = null): void;
}
