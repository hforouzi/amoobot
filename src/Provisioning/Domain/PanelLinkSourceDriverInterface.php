<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\PanelLinks;

/**
 * Drivers whose panel renders a client's config links itself (3x-ui with the clients API: Hosts, CIDR addresses, SNI
 * pools, remark templates and per-client host bindings are applied there). For such a service the panel's output is
 * the only source of its links (docs/design/multi-inbound-plans.md, 9.1): the bot stores it as the link snapshot and
 * never adds links it built itself.
 */
interface PanelLinkSourceDriverInterface
{
    /**
     * The client's links now: the subscription's (by subId) or, without one, the client's (by email).
     *
     * @param bool $recordHealth false: a failure is not counted towards the panel's "degraded" state (bulk refreshes)
     *
     * @throws \RuntimeException the panel could not be asked or refused (the message names no secret)
     */
    public function panelLinks(string $remoteId, ?string $subId, ?VpnPanel $panel = null, bool $recordHealth = true): PanelLinks;
}
