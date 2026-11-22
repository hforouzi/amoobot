<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Bot\Application\VpnAccessMessageFormatter;
use App\Entity\VpnService;
use App\Subscription\Application\SubscriptionPageView;
use App\Subscription\Application\SubscriptionPageViewFactory;
use App\Subscription\Domain\ClientPlatform;

/**
 * What a customer sees of one VPN service (VpnProductModule::customerView(), docs/design/product-modules.md 2.2 and
 * 2.4). Stands in for 2.2's CustomerServiceView until a second module needs a neutral shape: each method is today's
 * call, unchanged and run only when the caller asks for it, so the bot, the Mini App and the subscription page keep
 * the order of their panel calls and the exact view objects, arrays and texts they render.
 *
 * Access rules stay where they are: CustomerAccessGuard decides which link or config a customer may see, the
 * SubscriptionPageViewFactory builds only what a phone can reach, and the caller has already checked that the
 * service is the customer's (CustomerServiceFinder, the owner check, the page token).
 */
final class VpnCustomerView
{
    public function __construct(
        private readonly VpnService $service,
        private readonly SubscriptionPageViewFactory $pages,
        private readonly FinalConfigLinkProvider $configLinks,
        private readonly VpnAccessMessageFormatter $accessMessages,
        private readonly ServiceConfigDeliveryRefresher $deliveryRefresher,
        private readonly CustomerAccessGuard $accessGuard,
        private readonly ServiceInboundCounts $inboundCounts,
    ) {
    }

    public function service(): VpnService
    {
        return $this->service;
    }

    /** The subscription page (SubscriptionPageViewFactory::build()); null when it has nothing to show. */
    public function page(?ClientPlatform $platform): ?SubscriptionPageView
    {
        return $this->pages->build($this->service, $platform);
    }

    /** Status, plan, expiry and traffic, never a link (SubscriptionPageViewFactory::summary()): the service card. */
    public function summary(): ?SubscriptionPageView
    {
        return $this->pages->summary($this->service);
    }

    /** Fetch the configs from the panel before they are delivered (ServiceConfigDeliveryRefresher). */
    public function refreshBeforeDelivery(string $sourceFlow): ServiceConfigRefreshOutcome
    {
        return $this->deliveryRefresher->refreshBeforeDelivery($this->service, $sourceFlow);
    }

    /** The configs to deliver (FinalConfigLinkProvider), with the display guard's linksPending. */
    public function configLinkSet(string $sourceFlow): VpnConfigLinkSetResult
    {
        return $this->configLinks->getFinalLinkSetForService($this->service, $sourceFlow);
    }

    /**
     * The bot's access messages: subscription link and configs, or the notices that replace them
     * (VpnAccessMessageFormatter).
     *
     * @return list<string>
     */
    public function accessMessages(string $headline, string $sourceFlow): array
    {
        return $this->accessMessages->formatServiceAccessMessages($this->service, $headline, $sourceFlow);
    }

    /** The subscription link a client can reach, or null (CustomerAccessGuard::subscriptionUrl()). */
    public function subscriptionUrl(): ?string
    {
        return $this->accessGuard->subscriptionUrl($this->service);
    }

    /**
     * The configs a client can use, of those given (CustomerAccessGuard::configLinks()).
     *
     * @param array<int, string> $links
     *
     * @return list<string>
     */
    public function usableConfigLinks(array $links): array
    {
        return $this->accessGuard->configLinks($this->service, $links);
    }

    /**
     * A plan with several inbounds: how many servers, never which ones (ServiceInboundCounts::servers()).
     *
     * @return array{total: int, notConnected: int, retrying: int, stuck: int}|null
     */
    public function servers(): ?array
    {
        return $this->inboundCounts->servers($this->service);
    }
}
