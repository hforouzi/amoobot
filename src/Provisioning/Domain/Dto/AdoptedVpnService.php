<?php

declare(strict_types=1);

namespace App\Provisioning\Domain\Dto;

/**
 * A client that already exists on the panel for an order (left by an earlier, partly failed
 * provisioning attempt), rebuilt so the database row can be recreated instead of a second client.
 */
final readonly class AdoptedVpnService
{
    public const MATCHED_BY_UUID = 'uuid';
    public const MATCHED_BY_EMAIL = 'email';

    public function __construct(
        public CreatedVpnService $service,
        /** uuid or email */
        public string $matchedBy,
        /** Expiry as stored on the panel; null = unlimited or not reported. */
        public ?\DateTimeImmutable $expiresAt = null,
        /** Traffic limit as stored on the panel; null = unlimited or not reported. */
        public ?int $trafficLimitGb = null,
    ) {
    }
}
