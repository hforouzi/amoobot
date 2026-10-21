<?php

declare(strict_types=1);

namespace App\Subscription\Application;

use App\Bot\Application\QrCodeImage;
use App\Entity\VpnService;
use App\Provisioning\Application\CustomerAccessGuard;
use App\Provisioning\Application\FinalConfigLinkProvider;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Subscription\Domain\ClientPlatform;
use Psr\Clock\ClockInterface;

/**
 * Builds the subscription page of one service. Knows nothing about how the visitor got there (public token today,
 * a validated Mini App user later). Deleted services have no page (null).
 */
final class SubscriptionPageViewFactory
{
    private const GIB = 1073741824;

    public function __construct(
        private readonly CustomerAccessGuard $accessGuard,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        private readonly ClientAppCatalog $clientApps,
        private readonly AppDownloadCatalog $downloads,
        private readonly ClockInterface $clock,
    ) {
    }

    public function build(VpnService $service, ?ClientPlatform $platform): ?SubscriptionPageView
    {
        if (VpnServiceStatus::DELETED === $service->getStatus()) {
            return null;
        }

        $now = $this->clock->now();
        $expiresAt = $service->getExpiresAt();
        $limit = $this->limitBytes($service);
        $used = $this->usedBytes($service);
        $status = $this->status($service, $now, $limit, $used);
        $usable = SubscriptionPageView::STATUS_ACTIVE === $status;

        $subscriptionUrl = null;
        $configLinks = [];
        $withheld = false;
        if ($usable) {
            // Only what a client can reach (CustomerAccessGuard alerts the admins about the rest).
            $subscriptionUrl = $this->accessGuard->subscriptionUrl($service);
            $configLinks = $this->accessGuard->configLinks($service, $this->finalConfigLinkProvider->getFinalLinksForService($service, 'subscription_page'));
            $withheld = (null === $subscriptionUrl && '' !== trim((string) $service->getSubscriptionUrl())) || ([] === $configLinks && '' !== trim((string) $service->getConfigText()));
        }

        return new SubscriptionPageView(
            status: $status,
            planName: $service->getOrder()?->getPlan()->getTitle(),
            expiresAt: $expiresAt,
            daysLeft: null === $expiresAt ? null : max(0, intdiv($expiresAt->getTimestamp() - $now->getTimestamp(), 86400)),
            trafficLimitBytes: $limit,
            trafficUsedBytes: $used,
            trafficRemainingBytes: null === $limit ? null : max(0, $limit - $used),
            usedPercent: null === $limit ? null : (int) min(100, floor($used * 100 / $limit)),
            subscriptionUrl: $subscriptionUrl,
            qrDataUri: null !== $subscriptionUrl ? $this->qr($subscriptionUrl) : null,
            configLinks: $configLinks,
            accessWithheld: $withheld,
            platform: $platform,
            apps: $usable && null !== $subscriptionUrl ? $this->clientApps->forPage($subscriptionUrl, $this->profileName($service), $platform) : [],
            downloads: $this->downloads->forPage($platform),
        );
    }

    private function status(VpnService $service, \DateTimeImmutable $now, ?int $limit, int $used): string
    {
        return match (true) {
            VpnServiceStatus::SUSPENDED === $service->getStatus() => SubscriptionPageView::STATUS_SUSPENDED,
            VpnServiceStatus::EXPIRED === $service->getStatus(),
            null !== $service->getExpiresAt() && $service->getExpiresAt() <= $now => SubscriptionPageView::STATUS_EXPIRED,
            null !== $limit && $used >= $limit => SubscriptionPageView::STATUS_LIMITED,
            default => SubscriptionPageView::STATUS_ACTIVE,
        };
    }

    /** Null: unlimited. */
    private function limitBytes(VpnService $service): ?int
    {
        $limit = $service->getTrafficLimitBytes() ?? (null !== $service->getTrafficLimitGb() ? $service->getTrafficLimitGb() * self::GIB : null);

        return null !== $limit && $limit > 0 ? $limit : null;
    }

    private function usedBytes(VpnService $service): int
    {
        return max(0, $service->getTrafficUsedBytes() ?? (int) ($service->getTrafficUsedGb() ?? 0) * self::GIB);
    }

    /** The profile name a client app shows: the plan, else the shop-side service number (never the panel username). */
    private function profileName(VpnService $service): string
    {
        return $service->getOrder()?->getPlan()->getTitle() ?? sprintf('VPN %d', $service->getId() ?? 0);
    }

    private function qr(string $content): ?string
    {
        try {
            return 'data:image/svg+xml;base64,'.base64_encode(QrCodeImage::svg($content));
        } catch (\Throwable) {
            return null;
        }
    }
}
