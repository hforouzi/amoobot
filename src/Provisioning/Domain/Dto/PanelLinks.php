<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

/**
 * A client's config links as the panel renders them (3x-ui: clients/subLinks/{subId}, with Hosts applied), after
 * ShareLinkPolicy: $rejected items of the answer were dropped. $source names the endpoint ("subLinks" or "links").
 */
final readonly class PanelLinks
{
    /**
     * @param list<string> $links
     */
    public function __construct(
        public array $links,
        public int $rejected,
        public string $source,
        public ?string $subscriptionUrl,
    ) {
    }
}
