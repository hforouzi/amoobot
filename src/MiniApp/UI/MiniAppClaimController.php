<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\MiniApp\Security\MiniAppUser;
use App\Reseller\Application\ClaimService;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Domain\ClaimException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The claimer's minimal Mini App screen (docs/design/reseller-and-claim.md, 6.2): the app opened with
 * startapp=claim_<token> signs in, StartPayloadRouter turns the token into a preview and a ticket (ClaimBotHandler),
 * and the app opens /app/view/claim/{id}/{ticket}. The token itself never appears in a URL. The screen shows the
 * preview; "Add to my account" POSTs the ticket (CSRF and the session by the firewall) and the app opens the service.
 * Every unusable link gets the same answer. 404 while claim links are off. Rate limited per client IP (claim_attempt_ip)
 * and, inside ClaimService, per Telegram user.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppClaimController extends AbstractController
{
    private const TICKET = '[0-9a-z]{7}[A-Za-z0-9_-]{16}';
    private const SHOWN_ERRORS = [ClaimException::UNAVAILABLE, ClaimException::RATE_LIMITED, ClaimException::BUSY, ClaimException::OWN_SERVICE, ClaimException::ALREADY_YOURS];

    public function __construct(
        private readonly ClaimService $claims,
        private readonly ResellerSettings $settings,
        private readonly MiniAppFragments $fragments,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.claim_attempt_ip')]
        private readonly RateLimiterFactory $ipLimiter,
    ) {
    }

    #[Route('/app/view/claim/{id}/{ticket}', name: 'miniapp_claim', requirements: ['id' => '[1-9]\d{0,9}', 'ticket' => self::TICKET], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]
    public function show(Request $request, #[CurrentUser] MiniAppUser $user, int $id, string $ticket): Response
    {
        $this->assertOn();
        if (!$this->ipAllowed($request)) {
            return $this->failed($request, ClaimException::RATE_LIMITED);
        }
        try {
            $preview = $this->claims->previewForTicket($id, $ticket, $user->customer(), $user->telegramUserId());
        } catch (ClaimException $e) {
            return $this->failed($request, $e->error);
        }

        return $this->fragments->render($request, 'miniapp/view/claim.html.twig', ['preview' => $preview]);
    }

    #[Route('/app/view/claim/failed/{error}', name: 'miniapp_claim_failed', requirements: ['error' => '[a-z_]{1,32}'], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]
    public function failedView(Request $request, string $error): Response
    {
        $this->assertOn();

        return $this->failed($request, $error);
    }

    #[Route('/app/api/claim/{id}', name: 'miniapp_claim_accept', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'services'], methods: ['POST'])]
    public function accept(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $this->assertOn();
        $locale = MiniAppLocale::fromRequest($request);
        if (!$this->ipAllowed($request)) {
            return $this->answer($this->errorKey(ClaimException::RATE_LIMITED), $locale, Response::HTTP_TOO_MANY_REQUESTS);
        }
        $body = 'json' === $request->getContentTypeFormat() ? json_decode($request->getContent(), true) : null;
        $ticket = is_array($body) && is_string($body['ticket'] ?? null) ? $body['ticket'] : '';
        if (1 !== preg_match('/^'.self::TICKET.'$/D', $ticket)) {
            return $this->answer($this->errorKey(ClaimException::UNAVAILABLE), $locale, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            [, $service] = $this->claims->claim($id, $ticket, $user->customer(), $user->telegramUserId());
        } catch (ClaimException $e) {
            return $this->answer($this->errorKey($e->error), $locale, ClaimException::RATE_LIMITED === $e->error ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The service is now his: the app opens it (ownership is checked there again, CustomerServiceFinder).
        $response = $this->answer('claim.done', $locale);
        $response->setData(['message' => $this->translator->trans('claim.done', [], 'miniapp', $locale), 'redirect' => $this->generateUrl('miniapp_service', ['id' => $service->getId()])]);

        return $response;
    }

    private function failed(Request $request, string $error): Response
    {
        $error = in_array($error, self::SHOWN_ERRORS, true) ? $error : ClaimException::UNAVAILABLE;

        return $this->fragments->render($request, 'miniapp/view/claim_failed.html.twig', ['messageKey' => $this->errorKey($error)], Response::HTTP_OK);
    }

    private function errorKey(string $error): string
    {
        return match ($error) {
            ClaimException::RATE_LIMITED => 'claim.error.rate_limited',
            ClaimException::BUSY => 'claim.error.busy',
            ClaimException::OWN_SERVICE => 'claim.error.own_service',
            ClaimException::ALREADY_YOURS => 'claim.error.already_yours',
            default => 'claim.error.unavailable',
        };
    }

    private function ipAllowed(Request $request): bool
    {
        return $this->ipLimiter->create((string) $request->getClientIp())->consume()->isAccepted();
    }

    private function assertOn(): void
    {
        if (!$this->settings->claimsEnabled()) {
            throw new NotFoundHttpException();
        }
    }

    private function answer(string $key, string $locale, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse(['message' => $this->translator->trans($key, [], 'miniapp', $locale)], $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
