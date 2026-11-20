<?php

declare(strict_types=1);

namespace App\Subscription\Application;

use App\Branding\BrandSubscriptionPages;
use App\Entity\VpnService;
use App\Shared\Application\SiteUrls;
use App\Shared\Domain\PublicUrlPolicy;
use Twig\Attribute\AsTwigFunction;

/**
 * Absolute URLs of a service's public subscription page (/s/{token}) and of its app import bridge
 * (/s/{token}/open/{appId}); the only builder of both.
 *
 * For customers (bot messages and buttons, notifications, the Mini App, the reseller's copy link): on the brand domain
 * when "customer subscription pages on the brand domain" is active (BrandSubscriptionPages), else on the shop's own
 * domain (DEFAULT_URI outside a request, e.g. in the bot). For admins (forAdmin()): always DEFAULT_URI. Never the host
 * of the current request (SiteUrls).
 */
final class SubscriptionPageUrl
{
    public function __construct(
        private readonly SiteUrls $urls,
        private readonly BrandSubscriptionPages $brandPages,
    ) {
    }

    public function forService(VpnService $service): string
    {
        return $this->customer('subscription_page', ['token' => $service->getPublicToken()]);
    }

    /** The same page as admin screens show it (EasyAdmin, the Mini App admin section): DEFAULT_URI, switch or not. */
    public function forAdmin(VpnService $service): string
    {
        return $this->urls->operational('subscription_page', ['token' => $service->getPublicToken()]);
    }

    /** The import bridge of one client app: the Mini App's "add to app" button (templates/subscription/_apps.html.twig). */
    #[AsTwigFunction('subscription_bridge_url')]
    public function bridge(string $token, int $appId): string
    {
        return $this->customer('subscription_app_bridge', ['token' => $token, 'appId' => $appId]);
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

    /**
     * @param array<string, mixed> $parameters
     */
    private function customer(string $route, array $parameters): string
    {
        return $this->brandPages->active() ? $this->urls->customer($route, $parameters) : $this->urls->operational($route, $parameters);
    }
}
