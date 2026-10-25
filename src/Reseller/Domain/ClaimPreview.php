<?php

declare(strict_types=1);

namespace App\Reseller\Domain;

/**
 * What the person who opened a claim link is shown before anything changes (docs/design/reseller-and-claim.md, 3.3),
 * with the ticket that lets exactly him confirm it (ClaimTickets). No data of the current owner: only the reseller's
 * display name, if he set one.
 */
final class ClaimPreview
{
    public function __construct(
        public readonly int $claimId,
        public readonly string $ticket,
        public readonly int $serviceId,
        public readonly string $planTitle,
        public readonly ?int $trafficLimitBytes,
        public readonly ?int $trafficUsedBytes,
        public readonly ?\DateTimeImmutable $serviceExpiresAt,
        public readonly ?string $fromName,
        public readonly \DateTimeImmutable $linkExpiresAt,
    ) {
    }

    /** Traffic left in bytes, or null for unlimited. */
    public function trafficLeftBytes(): ?int
    {
        return null === $this->trafficLimitBytes ? null : max(0, $this->trafficLimitBytes - (int) $this->trafficUsedBytes);
    }
}
