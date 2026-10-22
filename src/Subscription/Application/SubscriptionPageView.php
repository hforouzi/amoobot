<?php

declare(strict_types=1);

namespace App\Subscription\Application;

use App\Subscription\Domain\ClientPlatform;

/**
 * Everything the customer subscription page shows about one service, already filtered for a customer (only addresses
 * a phone can reach, nothing about the panel or the customer). Built by SubscriptionPageViewFactory; rendered by
 * templates/subscription/_*.html.twig, so the later Mini App can render the same components for an authenticated user.
 */
final class SubscriptionPageView
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUSPENDED = 'suspended';
    /** Active, but the traffic is used up. */
    public const STATUS_LIMITED = 'limited';

    /**
     * @param list<string>           $configLinks
     * @param list<ClientAppView>    $apps        matching the visitor's platform first
     * @param list<AppDownloadView>  $downloads   for the visitor's platform first
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $planName,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?int $daysLeft,
        public readonly ?int $trafficLimitBytes,
        public readonly int $trafficUsedBytes,
        public readonly ?int $trafficRemainingBytes,
        public readonly ?int $usedPercent,
        public readonly ?string $subscriptionUrl,
        public readonly ?string $qrDataUri,
        public readonly array $configLinks,
        public readonly bool $accessWithheld,
        public readonly ?ClientPlatform $platform,
        public readonly array $apps = [],
        public readonly array $downloads = [],
    ) {
    }

    /** Links, QR and import buttons are only offered while the service works. */
    public function isUsable(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }
}
