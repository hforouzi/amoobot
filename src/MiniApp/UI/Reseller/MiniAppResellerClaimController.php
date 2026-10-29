<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Reseller;

use App\Bot\Application\QrCodeImage;
use App\MiniApp\Reseller\InvalidResellerInput;
use App\MiniApp\Reseller\MiniAppResellerAccess;
use App\MiniApp\Reseller\ResellerInput;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Reseller\Application\ClaimLinks;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Domain\ClaimException;
use App\Reseller\Domain\ResellerException;
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
 * A reseller's claim links in the Mini App (docs/design/reseller-and-claim.md, 3.1 / 3.6 / 6.2), only while
 * reseller.claims_enabled is on too (404 otherwise): make one for a service he sold and still owns, see his links
 * (service, status, dates; never who claimed), revoke an open one. Everything is ClaimLinks' (the bot's own service:
 * its checks, locks, the claim_create rate limit, one open link per service, a new link revoking the old one). Making
 * and revoking also count against the reseller section's own budget (miniapp_reseller, per Telegram user).
 *
 * The new link is in the answer once, rendered (the t.me links, copy / share buttons and a QR) and never stored: not in
 * a URL, not in checkout_request (so this call has no Idempotency-Key replay; a second tap makes a new link, which
 * revokes the first, as in the bot), not in a log.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppResellerClaimController extends AbstractController
{
    public function __construct(
        private readonly MiniAppFragments $fragments,
        private readonly MiniAppResellerAccess $access,
        private readonly ClaimLinks $claimLinks,
        private readonly ResellerCheckout $checkout,
        private readonly TranslatorInterface $translator,
        #[Autowire(service: 'limiter.miniapp_reseller')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    #[Route('/app/view/reseller/links', name: 'miniapp_reseller_links', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        $reseller = $this->access->requireClaims($user);
        try {
            $page = ResellerInput::page($request);
        } catch (InvalidResellerInput) {
            throw new NotFoundHttpException();
        }
        $result = $this->claimLinks->linksOf($reseller, $page);

        return $this->fragments->render($request, 'miniapp/reseller/links.html.twig', [
            'list' => ['items' => $result['items'], 'page' => $page, 'hasNext' => $result['hasNext']],
        ]);
    }

    #[Route('/app/api/reseller/services/{id}/claim-link', name: 'miniapp_reseller_claim_create', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $this->access->requireClaims($user);
        $locale = MiniAppLocale::fromRequest($request);
        if (!$this->limiter->create($user->telegramUserId())->consume()->isAccepted()) {
            return self::answer(['error' => 'rate_limited', 'message' => $this->translator->trans('checkout.message.rate_limited', [], 'miniapp', $locale)], Response::HTTP_TOO_MANY_REQUESTS);
        }
        // Not a live service he sold (anybody else's, or none): not found, like every other reseller id. Only his own
        // services can then be "not claimable" (claimed already, expired, ...).
        try {
            $this->checkout->findSoldService($user->customer(), $id);
        } catch (ResellerException) {
            return self::answer(['error' => 'not_found', 'message' => $this->translator->trans('reseller.error.not_found', [], 'miniapp', $locale)], Response::HTTP_NOT_FOUND);
        }
        try {
            $link = $this->claimLinks->createForReseller($user->customer(), $id);
        } catch (ClaimException $e) {
            [$key, $status] = match ($e->error) {
                ClaimException::RATE_LIMITED => ['reseller.claim.error.rate_limited', Response::HTTP_TOO_MANY_REQUESTS],
                ClaimException::BUSY => ['reseller.claim.error.busy', Response::HTTP_CONFLICT],
                ClaimException::FEATURE_OFF, ClaimException::RESELLER_UNAVAILABLE => ['reseller.error.not_found', Response::HTTP_NOT_FOUND],
                default => ['reseller.claim.error.not_claimable', Response::HTTP_CONFLICT],
            };

            return self::answer(['error' => $e->error, 'message' => $this->translator->trans($key, [], 'miniapp', $locale)], $status);
        }
        $shareable = $link->appLink ?? $link->botLink;
        $html = (string) $this->fragments->render($request, 'miniapp/reseller/_claim_link.html.twig', [
            'link' => $link,
            'serviceId' => $id,
            'shareable' => $shareable,
            'qr' => null !== $shareable ? self::qr($link->botLink ?? $shareable) : null,
            'shareUrl' => null !== $shareable ? 'https://t.me/share/url?'.http_build_query(['url' => $shareable], '', '&', PHP_QUERY_RFC3986) : null,
        ])->getContent();

        return self::answer(['message' => $this->translator->trans('reseller.claim.created', [], 'miniapp', $locale), 'html' => $html]);
    }

    /** Revokes one of his open links. Idempotent: a link that is no longer open (or not his) changes nothing. */
    #[Route('/app/api/reseller/links/{id}/revoke', name: 'miniapp_reseller_claim_revoke', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function revoke(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $this->access->requireClaims($user);
        $locale = MiniAppLocale::fromRequest($request);
        if (!$this->limiter->create($user->telegramUserId())->consume()->isAccepted()) {
            return self::answer(['error' => 'rate_limited', 'message' => $this->translator->trans('checkout.message.rate_limited', [], 'miniapp', $locale)], Response::HTTP_TOO_MANY_REQUESTS);
        }
        try {
            $revoked = $this->claimLinks->revokeByReseller($user->customer(), $id);
        } catch (ClaimException) {
            // Its row stayed locked (someone is using the link right now): nothing changed.
            return self::answer(['error' => ClaimException::BUSY, 'message' => $this->translator->trans('reseller.claim.error.busy', [], 'miniapp', $locale)], Response::HTTP_CONFLICT);
        }

        return self::answer(['message' => $this->translator->trans($revoked ? 'reseller.claim.revoked' : 'reseller.claim.not_open', [], 'miniapp', $locale)]);
    }

    private static function qr(string $content): ?string
    {
        try {
            return 'data:image/svg+xml;base64,'.base64_encode(QrCodeImage::svg($content));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function answer(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
