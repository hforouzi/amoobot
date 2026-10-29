<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

/**
 * The panel client cannot be given an owner by a full-row update, and trying again will not change that: it
 * authenticates by a password (Trojan, Shadowsocks) or the panel reports an id that is not a UUID (a row id, say).
 * Nothing was sent to the panel. ClaimPanelSync records it as "not applicable" instead of retrying.
 */
final class ClientOwnerNotApplicable extends \RuntimeException
{
}
