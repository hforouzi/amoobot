<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Reseller;

use App\Entity\VpnService;
use App\MiniApp\Admin\AdminConfirmTokens;
use App\MiniApp\Checkout\CheckoutIdempotency;
use App\MiniApp\Reseller\InvalidResellerInput;
use App\MiniApp\Reseller\MiniAppResellerAccess;
use App\MiniApp\Reseller\ResellerInput;
use App\MiniApp\Reseller\ResellerPurchases;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Reseller\Application\ClaimLinks;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Application\ResellerCustomers;
use App\Reseller\Application\SoldServices;
use App\Reseller\Domain\ResellerException;
use App\Shop\Application\SalesSettingsProvider;
use App\Subscription\Application\SubscriptionPageUrl;
use App\Subscription\Application\SubscriptionPageViewFactory;
use App\Wallet\Application\WalletService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The services a reseller sold (docs/design/reseller-and-claim.md, 6.2 / 3.5 D5): the list (search, filters, his
 * labels), one service, renewing / adding traffic at wholesale, and assigning it to one of his customer labels.
 *
 * Every id is checked against the signed-in reseller (ResellerCheckout::findSoldService: sold by him and not deleted;
 * 404 for anything else). While the service is still his own he gets what its owner gets: the in-app subscription page
 * (link, QR, configs), the bot's "send to chat" actions and usage refresh - the customer screens themselves, which check
 * ownership again (CustomerServiceFinder) - plus a claim link (S2). Once someone else owns it (a claim) he sees only
 * plan, status, expiry, traffic and when it was claimed, and may renew / add traffic at wholesale: never its links, its
 * subscription page or anything about the new owner (OrderServiceAccess / summary view, D5).
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppResellerServiceController extends AbstractController
{
    public function __construct(
        private readonly MiniAppFragments $fragments,
        private readonly MiniAppResellerAccess $access,
        private readonly ResellerCheckout $checkout,
        private readonly SoldServices $soldServices,
        private readonly ResellerCustomers $customers,
        private readonly ResellerPurchases $purchases,
        private readonly ClaimLinks $claimLinks,
        private readonly SubscriptionPageViewFactory $viewFactory,
        private readonly SubscriptionPageUrl $pageUrl,
        private readonly SalesSettingsProvider $salesSettings,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettings,
        private readonly WalletService $wallets,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/app/view/reseller/services', name: 'miniapp_reseller_services', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        $reseller = $this->access->require($user);
        try {
            $tab = ResellerInput::choice($request, 'tab', SoldServices::TABS, SoldServices::TAB_ALL);
            $query = ResellerInput::search($request);
            $customerId = ResellerInput::queryId($request, 'customer');
            $page = ResellerInput::page($request);
        } catch (InvalidResellerInput) {
            throw new NotFoundHttpException();
        }
        $customer = $customerId > 0 ? ($this->customers->find($reseller, $customerId) ?? throw new NotFoundHttpException()) : null;
        $list = $this->soldServices->list($reseller, $tab, $query, $customerId, $page);
        $ids = array_map(static fn (VpnService $s): int => (int) $s->getId(), $list['items']);

        return $this->fragments->render($request, 'miniapp/reseller/services.html.twig', [
            'list' => $list,
            'tab' => $tab,
            'tabs' => SoldServices::TABS,
            'query' => $query,
            'customer' => $customer,
            'labels' => $this->customers->labelsOf($reseller, $ids),
            'ownerId' => $reseller->getUser()->getId(),
        ]);
    }

    #[Route('/app/view/reseller/services/{id}', name: 'miniapp_reseller_service', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function show(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $reseller = $this->access->require($user);
        $service = $this->sold($user, $id);
        $claimed = $this->soldServices->isClaimed($reseller, $service);
        $claims = $this->access->claimsEnabled();
        $openLink = $claims && !$claimed ? $this->claimLinks->openLinkOf($service) : null;

        return $this->fragments->render($request, 'miniapp/reseller/service.html.twig', [
            'service' => $service,
            'view' => $this->viewFactory->summary($service),
            'claimed' => $claimed,
            // Only while it is still his: what its owner gets. Never computed for a claimed service.
            'pageUrl' => $claimed ? null : $this->pageUrl->forService($service),
            'claimedAt' => $claims && $claimed ? $this->claimLinks->claimedAwayAt($reseller, $service) : null,
            'claimsEnabled' => $claims,
            'openLink' => null !== $openLink && $openLink->getReseller()?->getId() === $reseller->getId() ? $openLink : null,
            'canMakeLink' => $claims && !$claimed && null === $this->claimLinks->resellerProblem($user->customer(), $service),
            'canRenew' => $this->salesSettings->renewalsEnabled(),
            'canAddTraffic' => $this->salesSettings->addTrafficEnabled() && $this->trafficAddonSettings->canPurchase(),
            'label' => $this->soldServices->labelOf($reseller, $service),
            'choices' => $this->customers->choices($reseller),
        ]);
    }

    #[Route('/app/view/reseller/services/{id}/renew', name: 'miniapp_reseller_renew', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function renewForm(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $this->access->require($user);
        $service = $this->sold($user, $id);
        try {
            $quote = $this->purchases->quote($user, ResellerPurchases::KIND_RENEWAL, $id, 0, 0);
        } catch (ResellerException $e) {
            return $this->refused($request, $e->error);
        }

        return $this->fragments->render($request, 'miniapp/reseller/renew.html.twig', [
            'service' => $service,
            'view' => $this->viewFactory->summary($service),
            'quote' => $quote,
            'balance' => $this->balance($user),
        ]);
    }

    #[Route('/app/view/reseller/services/{id}/add-traffic', name: 'miniapp_reseller_add_traffic', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function addTrafficForm(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $this->access->require($user);
        $service = $this->sold($user, $id);
        $minGb = $this->trafficAddonSettings->minGb();
        try {
            $quote = $this->purchases->quote($user, ResellerPurchases::KIND_TRAFFIC, $id, $minGb, 0);
        } catch (ResellerException $e) {
            return $this->refused($request, $e->error);
        }

        return $this->fragments->render($request, 'miniapp/reseller/add_traffic.html.twig', [
            'service' => $service,
            'view' => $this->viewFactory->summary($service),
            'quote' => $quote,
            'balance' => $this->balance($user),
            'minGb' => $minGb,
            'maxGb' => $this->trafficAddonSettings->maxGb(),
        ]);
    }

    #[Route('/app/api/reseller/services/{id}/renew/quote', name: 'miniapp_reseller_renew_quote', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function renewQuote(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->quoteAnswer($request, $user, ResellerPurchases::KIND_RENEWAL, $id, false);
    }

    #[Route('/app/api/reseller/services/{id}/renew', name: 'miniapp_reseller_renew_buy', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function renew(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->purchaseAnswer($request, $user, ResellerPurchases::KIND_RENEWAL, $id, false);
    }

    #[Route('/app/api/reseller/services/{id}/add-traffic/quote', name: 'miniapp_reseller_add_traffic_quote', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function addTrafficQuote(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->quoteAnswer($request, $user, ResellerPurchases::KIND_TRAFFIC, $id, true);
    }

    #[Route('/app/api/reseller/services/{id}/add-traffic', name: 'miniapp_reseller_add_traffic_buy', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function addTraffic(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        return $this->purchaseAnswer($request, $user, ResellerPurchases::KIND_TRAFFIC, $id, true);
    }

    /**
     * Assigns the service to one of his labels ({customerId}), or unassigns it ({customerId: ""}, or {} as the form
     * sends an empty choice). A body that is not a JSON object is 400 and changes nothing.
     */
    #[Route('/app/api/reseller/services/{id}/customer', name: 'miniapp_reseller_service_customer', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function assign(Request $request, #[CurrentUser] MiniAppUser $user, int $id): JsonResponse
    {
        $reseller = $this->access->require($user);
        $locale = MiniAppLocale::fromRequest($request);
        if (!$this->purchases->throttle($user)) {
            return self::answer(['error' => 'rate_limited', 'message' => $this->purchases->refusalMessage('rate_limited', $locale)], Response::HTTP_TOO_MANY_REQUESTS);
        }
        try {
            $body = ResellerInput::body($request);
            $customerId = null === ($body['customerId'] ?? null) || '' === $body['customerId'] ? null : ResellerInput::id($body, 'customerId');
        } catch (InvalidResellerInput $e) {
            if (ResellerInput::BODY === $e->field) {
                return $this->invalidInput($e, $locale);
            }

            return self::answer(['error' => 'not_found', 'message' => $this->translator->trans('reseller.error.not_found', [], 'miniapp', $locale)], Response::HTTP_NOT_FOUND);
        }
        if (!$this->customers->assign($reseller, $id, $customerId)) {
            return self::answer(['error' => 'not_found', 'message' => $this->translator->trans('reseller.error.not_found', [], 'miniapp', $locale)], Response::HTTP_NOT_FOUND);
        }

        return self::answer(['message' => $this->translator->trans(null === $customerId ? 'reseller.customer.unassigned' : 'reseller.customer.assigned', [], 'miniapp', $locale)]);
    }

    private function quoteAnswer(Request $request, MiniAppUser $user, string $kind, int $id, bool $asksTraffic): JsonResponse
    {
        $locale = MiniAppLocale::fromRequest($request);
        if (!$this->purchases->throttle($user)) {
            return self::answer(['error' => 'rate_limited', 'message' => $this->purchases->refusalMessage('rate_limited', $locale)], Response::HTTP_TOO_MANY_REQUESTS);
        }
        try {
            $trafficGb = $asksTraffic ? ResellerInput::optionalNumber(ResellerInput::body($request), 'trafficGb') : 0;
            $quote = $this->purchases->quote($user, $kind, $id, $trafficGb, 0);
        } catch (InvalidResellerInput $e) {
            return $this->invalidInput($e, $locale);
        } catch (ResellerException $e) {
            $status = match ($e->error) {
                'service_not_sold_by_you' => Response::HTTP_NOT_FOUND,
                'invalid_options' => Response::HTTP_UNPROCESSABLE_ENTITY,
                default => Response::HTTP_CONFLICT,
            };

            return self::answer(['error' => $e->error, 'message' => $this->purchases->refusalMessage($e->error, $locale)], $status);
        }
        $html = (string) $this->fragments->render($request, 'miniapp/reseller/_price.html.twig', ['quote' => $quote, 'balance' => $this->balance($user)])->getContent();

        return self::answer(['wholesaleAmount' => $quote->wholesaleAmount, 'retailAmount' => $quote->retailAmount, 'html' => $html]);
    }

    private function purchaseAnswer(Request $request, MiniAppUser $user, string $kind, int $id, bool $asksTraffic): JsonResponse
    {
        $locale = MiniAppLocale::fromRequest($request);
        try {
            $trafficGb = $asksTraffic ? ResellerInput::optionalNumber(ResellerInput::body($request), 'trafficGb') : 0;
        } catch (InvalidResellerInput $e) {
            return $this->invalidInput($e, $locale);
        }
        [$status, $answer] = $this->purchases->purchase($user, $kind, $id, $trafficGb, 0, $request->headers->get(CheckoutIdempotency::HEADER), $request->headers->get(AdminConfirmTokens::HEADER), $locale);

        return self::answer($answer, $status);
    }

    /**
     * @throws NotFoundHttpException not a live service he sold
     */
    private function sold(MiniAppUser $user, int $id): VpnService
    {
        try {
            return $this->checkout->findSoldService($user->customer(), $id);
        } catch (ResellerException) {
            throw new NotFoundHttpException();
        }
    }

    private function refused(Request $request, string $error): Response
    {
        if (in_array($error, ['service_not_sold_by_you', 'reseller_unavailable'], true)) {
            throw new NotFoundHttpException();
        }

        return $this->fragments->render($request, 'miniapp/reseller/refused.html.twig', ['message' => $this->purchases->refusalMessage($error, MiniAppLocale::fromRequest($request))]);
    }

    private function balance(MiniAppUser $user): int
    {
        return $this->wallets->find($user->customer())?->getBalance() ?? 0;
    }

    /** An unreadable body is 400 (ResellerInput::body), a value off its pattern 422. */
    private function invalidInput(InvalidResellerInput $e, string $locale): JsonResponse
    {
        if (ResellerInput::BODY === $e->field) {
            return self::answer(['error' => 'bad_request', 'message' => $this->purchases->refusalMessage('bad_request', $locale)], Response::HTTP_BAD_REQUEST);
        }

        return self::answer(['error' => 'invalid_options', 'message' => $this->purchases->refusalMessage('invalid_options', $locale)], Response::HTTP_UNPROCESSABLE_ENTITY);
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
