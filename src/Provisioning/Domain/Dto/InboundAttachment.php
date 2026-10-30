<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

use App\Entity\VpnInbound;

/**
 * Whether a client is attached to one additional inbound of its plan on the panel (docs/design/multi-inbound-plans.md
 * 3.2), as read back from the panel after the attach. $error says why not; it is safe to store and show (no URL,
 * credential or subscription id).
 */
final readonly class InboundAttachment
{
    public function __construct(
        public VpnInbound $inbound,
        public bool $attached,
        public ?string $error = null,
    ) {
    }
}
