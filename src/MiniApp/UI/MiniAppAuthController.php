<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\MiniApp\Admin\AdminRouteAccess;
use App\Security\AdminAccessPolicy;
use App\Bot\Application\DeepLink\StartPayloadRouter;
use App\Bot\Application\TelegramUserResolver;
use App\MiniApp\Auth\InitDataRejected;
use App\MiniApp\Auth\InitDataValidator;
use App\MiniApp\Security\MiniAppAuthenticator;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\Session\MiniAppSessionManager;
use App\MiniApp\Session\SessionTokenTransport;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mini App login: Telegram.WebApp.initData in, a server-side session out (MiniAppSessionManager, delivered by
 * SessionTokenTransport). Rate limited per client address (every attempt) and per Telegram user (after the HMAC
 * check, so nobody can lock a user out with forged data). Failures are logged with the reason and the address only.
 *
 * A start parameter (startapp=..., inside the signed initData) goes through the shared deep-link router
 * (StartPayloadRouter, as /start <payload> in the bot): when a handler takes it, the answer names the section the app
 * opens first ("start"). Without one, or when the handler is off, the answer is exactly as before.
 */
final class MiniAppAuthController extends AbstractController
{
    public function __construct(
        private readonly InitDataValidator $validator,
        private readonly TelegramUserResolver $userResolver,
        private readonly MiniAppSessionManager $sessions,
        private readonly SessionTokenTransport $transport,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.miniapp_auth_ip')]
        private readonly RateLimiterFactory $ipLimiter,
        #[Autowire(service: 'limiter.miniapp_auth_user')]
        private readonly RateLimiterFactory $userLimiter,
        private readonly AdminAccessPolicy $accessPolicy,
        private readonly AdminRouteAccess $adminAccess,
        private readonly StartPayloadRouter $startPayloadRouter,
    ) {
    }

    #[Route('/app/auth', name: 'miniapp_auth', methods: ['POST'])]
    public function auth(Request $request): JsonResponse
    {
        $limit = $this->ipLimiter->create((string) $request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return $this->tooManyRequests($limit);
        }

        // JSON only: a cross-site form cannot send it without a CORS preflight (no login CSRF).
        $body = 'json' === $request->getContentTypeFormat() ? json_decode($request->getContent(), true) : null;
        $initData = is_array($body) && is_string($body['initData'] ?? null) ? $body['initData'] : '';

        try {
            $data = $this->validator->validate($initData);
        } catch (InitDataRejected $e) {
            $this->logger->warning('Mini App auth rejected', ['reason' => $e->reason, 'ip' => $request->getClientIp()]);

            return MiniAppAuthenticator::error(InitDataRejected::EXPIRED === $e->reason ? 'expired' : 'invalid', Response::HTTP_UNAUTHORIZED);
        }

        $limit = $this->userLimiter->create($data->telegramUserId())->consume();
        if (!$limit->isAccepted()) {
            $this->logger->warning('Mini App auth rate limited', ['reason' => 'telegram_user', 'ip' => $request->getClientIp()]);

            return $this->tooManyRequests($limit);
        }

        // The same TelegramAccount/User the bot would create or update for this Telegram user.
        $account = $this->userResolver->resolveFromTelegramUser($data->user);
        // An admin's session lasts only as long as admin rights may (AdminRouteAccess re-checks it on admin routes).
        $ttl = [] !== $this->accessPolicy->permissions($this->accessPolicy->botActor($account->getTelegramId())) ? $this->adminAccess->sessionTtl() : null;
        $session = $this->sessions->issue($account->getTelegramId(), $request->headers->get('User-Agent'), $ttl);
        $start = $this->startPayloadRouter->routeMiniApp($account, $data->startParam);
        $response = $this->transport->deliver($session, null === $start ? [] : ['start' => $start]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/app/logout', name: 'miniapp_logout', methods: ['POST'])]
    #[IsGranted(MiniAppUser::ROLE)]
    public function logout(#[CurrentUser] MiniAppUser $user): Response
    {
        $this->sessions->revoke($user->session);

        return new Response('', Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/app/api/me', name: 'miniapp_me', methods: ['GET'])]
    #[IsGranted(MiniAppUser::ROLE)]
    public function me(#[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $response = new JsonResponse(['firstName' => (string) $user->account->getFirstName(), 'isAdmin' => null !== $user->admin]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function tooManyRequests(RateLimit $limit): JsonResponse
    {
        $response = MiniAppAuthenticator::error('rate_limited', Response::HTTP_TOO_MANY_REQUESTS);
        $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

        return $response;
    }
}
