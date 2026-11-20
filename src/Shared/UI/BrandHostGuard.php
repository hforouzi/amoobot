<?php

declare(strict_types=1);

namespace App\Shared\UI;

use App\Branding\BrandSubscriptionPages;
use App\Payment\Domain\PaymentReturnUrl;
use App\Shared\Application\BrandDomain;
use App\Subscription\UI\SubscriptionPageController;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The brand host's allowlist, enforced here whatever nginx does: only the Mini App, its admin section included (behind
 * its own sign-in and AdminAccessPolicy, as on the main host), and the payment return page (with the logo it shows).
 * While "customer subscription pages on the brand domain" is on (BrandSubscriptionPages::active()) also the
 * subscription page and the app import bridge (/s/{token}, /s/{token}/open/{appId}).
 * Anything else is 404, before routing and before any firewall, so the answer is the same for every method and for a
 * signed-in admin. The old host is not touched.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class BrandHostGuard
{
    private const SERVED = '#^/(?:app(?:/.*)?|payment/return/'.PaymentReturnUrl::TOKEN_PATTERN.'(?:/cancel)?|brand/logo/[a-f0-9]{32}\.(?:png|jpg|webp))$#D';
    private const SUBSCRIPTION_PAGES = '#^/s/'.SubscriptionPageController::TOKEN_PATTERN.'(?:/open/[0-9]+)?$#D';

    public function __construct(
        private readonly BrandDomain $brand,
        private readonly BrandSubscriptionPages $subscriptionPages,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->brand->isBrandRequest()) {
            return;
        }
        $path = $event->getRequest()->getPathInfo();
        // The switch is read only for a subscription path (one query), never for anything else.
        if (self::isServed($path) || (self::isSubscriptionPage($path) && $this->subscriptionPages->active())) {
            return;
        }
        $event->setResponse(new Response('Not Found', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']));
    }

    /** The router matches the decoded path, so this judges the same text: /%61dmin is /admin. */
    public static function isServed(string $path): bool
    {
        return 1 === preg_match(self::SERVED, rawurldecode($path));
    }

    /** The subscription page or an app import bridge: served on the brand host only while the switch is on. */
    public static function isSubscriptionPage(string $path): bool
    {
        return 1 === preg_match(self::SUBSCRIPTION_PAGES, rawurldecode($path));
    }
}
