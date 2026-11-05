<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\InboundMoveAttachResult;

/**
 * Drivers that can put an existing client on a plan's new primary inbound B before the service is switched to it
 * (docs/design/plan-inbound-change.md 4.3 step 2, PR P4): 3x-ui with the clients API. A panel whose driver does not
 * implement it (legacy mode, other panel types, the dummy driver) is never moved: the runner skips such a service
 * (NOT_MODERN or "not a real panel") and sends nothing.
 *
 * The step, at most three panel calls, each within the open PanelCallBudget pass (if any):
 * 1. GET the client. Unreadable, missing, without an inbound list or a UUID, or another client under the same email
 *    (UUID): failed, nothing sent;
 * 2. B listed: done without a write (idempotent: attached by hand, or by an earlier pass that died);
 *    on neither A (the inbound the service's remote id records) nor B: failed, nothing sent (a hand edit);
 * 3. one attach call with B only, then a GET: done only when that read lists B. A lost or garbled answer is read back
 *    the same way; a refused call is failed.
 */
interface InboundMoveDriverInterface
{
    /**
     * @param bool     $recordHealth false: a failing call is not counted towards the panel's "degraded" state (a bulk run)
     * @param int|null $expectedFrom the remote inbound the client must be on when B is missing, instead of the one the
     *                               remote id records (P6: the retire step re-attaches B after the switch, when the remote
     *                               id already names B and the client must still be on the old inbound A)
     *
     * Never throws for a panel outcome: every case is an InboundMoveAttachResult.
     */
    public function attachForMove(string $remoteId, VpnInbound $target, ?VpnPanel $panel = null, bool $recordHealth = true, ?int $expectedFrom = null): InboundMoveAttachResult;

    /**
     * Whether the panel still has the inbound with this remote id (GET panel/api/inbounds/list; P6, 4.3 step 5: a detach
     * of an inbound deleted on the panel fails, and the step counts as done only when this read confirms it is gone).
     *
     * @return bool|null null: the panel could not be read (never "gone")
     */
    public function panelHasInbound(VpnPanel $panel, int $remoteInboundId, bool $recordHealth = true): ?bool;
}
