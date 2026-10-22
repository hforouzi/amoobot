<?php

declare(strict_types=1);

namespace App\Subscription\Application;

use App\Entity\VpnService;
use App\Provisioning\Domain\PublicUrlPolicy;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Absolute URL of a service's public subscription page (/s/{token}) on the shop's own domain (DEFAULT_URI outside a
 * request, e.g. in the bot).
 */
final class SubscriptionPageUrl
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function forService(VpnService $service): string
    {
        return $this->urlGenerator->generate('subscription_page', ['token' => $service->getPublicToken()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * For a Telegram URL button: only an address a phone can open. Telegram refuses the whole message when a button
     * points to localhost or a private address (a shop without DEFAULT_URI), so then there is no button.
     */
    public function forTelegram(VpnService $service): ?string
    {
        $url = $this->forService($service);

        return PublicUrlPolicy::isPubliclyUsableUrl($url) ? $url : null;
    }
}
