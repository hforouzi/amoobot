<?php

declare(strict_types=1);

namespace App\Shared\UI;

use App\Payment\Domain\PaymentReturnUrl;
use App\Shared\Application\BrandDomain;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The brand host's allowlist, enforced here whatever nginx does: only the Mini App without its admin section, and the
 * payment return page (with the logo it shows). Anything else is 404, before routing and before any firewall, so the
 * answer is the same for every method and for a signed-in admin. The old host is not touched.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class BrandHostGuard
{
    private const SERVED = '#^/(?:app(?:/.*)?|payment/return/'.PaymentReturnUrl::TOKEN_PATTERN.'(?:/cancel)?|brand/logo/[a-f0-9]{32}\.(?:png|jpg|webp))$#D';
    private const MINIAPP_ADMIN = '#^/app/(?:api/)?admin(?:/|$)#D';

    public function __construct(private readonly BrandDomain $brand)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->brand->isBrandRequest() && !self::isServed($event->getRequest()->getPathInfo())) {
            $event->setResponse(new Response('Not Found', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']));
        }
    }

    /** The router matches the decoded path, so this judges the same text: /app/%61dmin is /app/admin. */
    public static function isServed(string $path): bool
    {
        $path = rawurldecode($path);

        return 1 === preg_match(self::SERVED, $path) && 1 !== preg_match(self::MINIAPP_ADMIN, $path);
    }
}
