<?php

declare(strict_types=1);

namespace App\MiniApp\Security;

use App\Entity\TelegramAccount;
use App\MiniApp\Session\MiniAppSessionManager;
use App\MiniApp\Session\SessionTokenTransport;
use App\Security\AdminAccessPolicy;
use App\Security\AdminActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates every /app request (firewall "miniapp", stateless) by its session token. A state-changing request
 * also needs the session's CSRF token in X-CSRF-Token (403 otherwise). The admin identity is looked up again on every
 * request, so a deactivated or unlinked admin loses admin rights at once, without waiting for the session to end.
 */
final class MiniAppAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    /** Pages that work without a session: the entry page and the login itself. */
    public const PUBLIC_PATHS = ['/app', '/app/auth'];

    public function __construct(
        private readonly SessionTokenTransport $transport,
        private readonly MiniAppSessionManager $sessions,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminAccessPolicy $accessPolicy,
    ) {
    }

    public function supports(Request $request): bool
    {
        return !in_array($request->getPathInfo(), self::PUBLIC_PATHS, true) && null !== $this->transport->extract($request);
    }

    public function authenticate(Request $request): Passport
    {
        $token = (string) $this->transport->extract($request);
        $session = $this->sessions->find($token, $request->headers->get('User-Agent'));
        if (null === $session) {
            throw new BadCredentialsException('Invalid Mini App session.');
        }
        if (!$request->isMethodSafe() && !$this->sessions->isValidCsrfToken($token, $request->headers->get('X-CSRF-Token'))) {
            throw new CsrfRejected('Invalid CSRF token.');
        }

        return new SelfValidatingPassport(new UserBadge($session->getTelegramUserId(), function (string $telegramUserId) use ($session): MiniAppUser {
            $account = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => $telegramUserId]);
            if (!$account instanceof TelegramAccount) {
                throw new BadCredentialsException('Unknown Telegram account.');
            }

            return new MiniAppUser($account, $session, $this->accessPolicy->botActor($telegramUserId)?->withSource(AdminActor::SOURCE_MINIAPP));
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $exception instanceof CsrfRejected ? self::error('csrf', Response::HTTP_FORBIDDEN) : self::error('unauthenticated', Response::HTTP_UNAUTHORIZED);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::error('unauthenticated', Response::HTTP_UNAUTHORIZED);
    }

    public static function error(string $code, int $status): JsonResponse
    {
        $response = new JsonResponse(['error' => $code], $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
