<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

final readonly class VpnConfigLinkSetResult
{
    /**
     * @param list<string> $finalLinks
     * @param bool         $linksPending the service's inbound changed after its links were last read from the panel
     *                                   (FinalConfigLinkProvider::linksPending()): $finalLinks is empty on purpose and
     *                                   the customer is told the links are being updated (LinksUpdatingNotice)
     */
    public function __construct(
        public int $rawCount,
        public int $formattedCount,
        public array $finalLinks,
        public int $droppedDuplicateCount,
        public bool $linksPending = false,
    ) {
    }
}
