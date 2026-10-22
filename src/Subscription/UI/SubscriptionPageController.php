<?php

declare(strict_types=1);

namespace App\Subscription\UI;

use App\Entity\VpnService;
use App\Subscription\Application\SubscriptionPageViewFactory;
use App\Subscription\Domain\ClientPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The customer's subscription page, opened from the bot (URL button), a browser or a Telegram web_app button.
 *
 * No login and no cookies: the random per-service token in the URL is the only key (rotatable by admins), every
 * request counts against a per-IP rate limit (wrong tokens included), and an unknown token and a deleted service get
 * the same neutral 404. The URL is a bearer secret: never cached, indexed or sent as a Referer.
 */
final class SubscriptionPageController extends AbstractController
{
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SubscriptionPageViewFactory $viewFactory,
        #[Autowire(service: 'limiter.subscription_page')]
        private readonly RateLimiterFactory $limiter,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    #[Route('/s/{token}', name: 'subscription_page', requirements: ['token' => self::TOKEN_PATTERN], methods: ['GET'])]
    public function show(Request $request, string $token): Response
    {
        // Persian by default; ?lang=en switches (a link on the page, no cookie).
        $locale = 'en' === $request->query->get('lang') ? 'en' : 'fa';
        $this->localeSwitcher->setLocale($locale);
        $request->setLocale($locale);

        $limit = $this->limiter->create((string) $request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            $response = $this->page('subscription/not_found.html.twig', [], Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        $service = $this->entityManager->getRepository(VpnService::class)->findOneBy(['publicToken' => $token]);
        $platform = ClientPlatform::tryFrom((string) $request->query->get('platform')) ?? ClientPlatform::fromUserAgent($request->headers->get('User-Agent'));
        $view = $service instanceof VpnService ? $this->viewFactory->build($service, $platform) : null;
        if (null === $view) {
            return $this->page('subscription/not_found.html.twig', [], Response::HTTP_NOT_FOUND);
        }

        return $this->page('subscription/page.html.twig', [
            'view' => $view,
            'locale' => $request->getLocale(),
            'platforms' => ClientPlatform::cases(),
        ]);
    }

    /**
     * The import bridge for one client app: a page the Mini App opens in the external browser (Telegram's WebView
     * blocks custom schemes such as hiddify:// and v2rayng://), with one big button carrying the app's import link
     * and the subscription link to copy as a fallback. Same key, limits and headers as the subscription page; only
     * a working subscription of an active app that has an import link has a bridge (404 otherwise).
     */
    #[Route('/s/{token}/open/{appId}', name: 'subscription_app_bridge', requirements: ['token' => self::TOKEN_PATTERN, 'appId' => '\d+'], methods: ['GET'])]
    public function bridge(Request $request, string $token, int $appId): Response
    {
        $locale = 'en' === $request->query->get('lang') ? 'en' : 'fa';
        $this->localeSwitcher->setLocale($locale);
        $request->setLocale($locale);

        if (!$this->limiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return $this->page('subscription/not_found.html.twig', [], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $service = $this->entityManager->getRepository(VpnService::class)->findOneBy(['publicToken' => $token]);
        $platform = ClientPlatform::fromUserAgent($request->headers->get('User-Agent'));
        $view = $service instanceof VpnService ? $this->viewFactory->build($service, $platform) : null;
        $app = null;
        foreach (null !== $view && $view->isUsable() ? $view->apps : [] as $candidate) {
            if ($appId === $candidate->id && null !== $candidate->importUrl) {
                $app = $candidate;
            }
        }
        if (null === $view || null === $app) {
            return $this->page('subscription/not_found.html.twig', [], Response::HTTP_NOT_FOUND);
        }

        return $this->page('subscription/bridge.html.twig', ['view' => $view, 'clientApp' => $app, 'locale' => $locale]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        $response = $this->render($template, $parameters, new Response('', $status));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Inline styles/scripts only; the Telegram WebApp script is loaded only inside Telegram (see the template).
        $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'unsafe-inline' https://telegram.org; connect-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors https://web.telegram.org 'self'");

        return $response;
    }
}
