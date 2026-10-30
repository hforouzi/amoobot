<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

use App\Entity\VpnInbound;

/**
 * Whether a client is attached to one additional inbound of its plan on the panel (docs/design/multi-inbound-plans.md
 * 3.2), as read back from the panel after the attach. $error says why not; it is safe to store and show (no URL,
 * credential or subscription id).
 *
 * $pending (M2c F2/F5): not known. The attach was sent but the panel could not be read back, or it was not tried within
 * the time budget; the row stays pending and the scheduled run checks it on the panel (reconcile) or attaches it.
 */
final readonly class InboundAttachment
{
    public function __construct(
        public VpnInbound $inbound,
        public bool $attached,
        public ?string $error = null,
        public bool $pending = false,
    ) {
    }

    public static function pending(VpnInbound $inbound, string $why): self
    {
        return new self($inbound, false, $why, true);
    }
}
