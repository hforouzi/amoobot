<?php

declare(strict_types=1);

namespace App\MiniApp\UI\Reseller;

use App\MiniApp\Checkout\CheckoutIdempotency;
use App\MiniApp\Admin\AdminConfirmTokens;
use App\MiniApp\Reseller\InvalidResellerInput;
use App\MiniApp\Reseller\MiniAppResellerAccess;
use App\MiniApp\Reseller\ResellerInput;
use App\MiniApp\Reseller\ResellerPurchases;
use App\MiniApp\Security\MiniAppUser;
use App\MiniApp\UI\MiniAppFragments;
use App\MiniApp\UI\MiniAppLocale;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Application\ResellerDashboard;
use App\Reseller\Application\ResellerCustomers;
use App\Reseller\Domain\ResellerException;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The reseller section of the Mini App (docs/design/reseller-and-claim.md, 6.2 / S3): the dashboard and buying a new
 * service at wholesale. Only for an active reseller while reseller.enabled is on (MiniAppResellerAccess; 404 for
 * anybody else, also from MiniAppSectionGuard). No logic here: prices, confirmations and purchases are ResellerCheckout's
 * (through ResellerPurchases, the bot's own path), figures ResellerDashboard's. The client sends ids and choices,
 * never an amount; CSRF and the session are the firewall's.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppResellerController extends AbstractController
{
    public function __construct(
        private readonly MiniAppFragments $fragments,
        private readonly MiniAppResellerAccess $access,
        private readonly ResellerCheckout $checkout,
        private readonly ResellerDashboard $dashboard,
        private readonly ResellerCustomers $customers,
        private readonly ResellerPurchases $purchases,
        private readonly WalletService $wallets,
        private readonly WalletSettings $walletSettings,
    ) {
    }

    #[Route('/app/view/reseller', name: 'miniapp_reseller', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function dashboard(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        $reseller = $this->access->require($user);
        $claims = $this->access->claimsEnabled();

        return $this->fragments->render($request, 'miniapp/reseller/dashboard.html.twig', [
            'reseller' => $reseller,
            'wallet' => $this->wallets->find($user->customer()),
            'walletEnabled' => $this->walletSettings->enabled(),
            'remainingToday' => $this->checkout->remainingToday($reseller),
            'sales' => $this->dashboard->sales($reseller),
            'counts' => $this->dashboard->serviceCounts($reseller),
            'customerCount' => $this->customers->count($reseller),
            'claimsEnabled' => $claims,
            'openLinks' => $claims ? $this->dashboard->openLinks($reseller) : 0,
            'recentOrders' => $this->dashboard->recentOrders($reseller),
        ]);
    }

    /** The plans he can buy now at wholesale (a customizable plan: its lowest choice, "from"); or why none. */
    #[Route('/app/view/reseller/new', name: 'miniapp_reseller_new', defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function newService(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        $this->access->require($user);
        try {
            $result = $this->checkout->newServiceOffers($user->customer());
        } catch (ResellerException) {
            throw new NotFoundHttpException();
        }

        return $this->fragments->render($request, 'miniapp/reseller/new.html.twig', [
            'offers' => $result['offers'],
            'reason' => $result['reason'],
        ]);
    }

    /** One plan: its traffic / days within the plan's range (customizable) and the server's wholesale quote. */
    #[Route('/app/view/reseller/new/{id}', name: 'miniapp_reseller_plan', requirements: ['id' => '[1-9]\d{0,9}'], defaults: ['_miniapp_section' => 'reseller'], methods: ['GET'])]
    public function plan(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $this->access->require($user);
        try {
            $choices = $this->checkout->planChoices($user->customer(), $id);
            [, $plan, $quote] = $this->checkout->quoteNewService(
                $user->customer(),
                $id,
                $choices['asksTraffic'] ? $choices['minTraffic'] : null,
                $choices['asksDuration'] ? $choices['minDays'] : null,
            );
        } catch (ResellerException $e) {
            if (in_array($e->error, ['plan_unavailable', 'reseller_unavailable'], true)) {
                throw new NotFoundHttpException();
            }

            return $this->fragments->render($request, 'miniapp/reseller/refused.html.twig', ['message' => $this->purchases->refusalMessage($e->error, MiniAppLocale::fromRequest($request))]);
        }

        return $this->fragments->render($request, 'miniapp/reseller/plan.html.twig', [
            'plan' => $plan,
            'choices' => $choices,
            'quote' => $quote,
            'balance' => $this->wallets->find($user->customer())?->getBalance() ?? 0,
        ]);
    }

    /** The wholesale price of these choices now (nothing is written). */
    #[Route('/app/api/reseller/new/quote', name: 'miniapp_reseller_new_quote', defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function quote(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $locale = MiniAppLocale::fromRequest($request);
        try {
            $body = ResellerInput::body($request);
            $quote = $this->purchases->quote($user, ResellerPurchases::KIND_NEW, ResellerInput::id($body, 'planId'), ResellerInput::optionalNumber($body, 'trafficGb'), ResellerInput::optionalNumber($body, 'durationDays'));
        } catch (InvalidResellerInput $e) {
            return $this->invalidInput($e, $locale);
        } catch (ResellerException $e) {
            return self::answer(['error' => $e->error, 'message' => $this->purchases->refusalMessage($e->error, $locale)], 'invalid_options' === $e->error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT);
        }
        $balance = $this->wallets->find($user->customer())?->getBalance() ?? 0;
        $html = (string) $this->fragments->render($request, 'miniapp/reseller/_price.html.twig', ['quote' => $quote, 'balance' => $balance])->getContent();

        return self::answer(['wholesaleAmount' => $quote->wholesaleAmount, 'retailAmount' => $quote->retailAmount, 'html' => $html]);
    }

    /**
     * Buys the new service from the wallet: first answered 428 with the confirmation, then (on OK, same key) bought for
     * exactly that confirmation (ResellerPurchases).
     */
    #[Route('/app/api/reseller/new', name: 'miniapp_reseller_buy', defaults: ['_miniapp_section' => 'reseller'], methods: ['POST'])]
    public function buy(Request $request, #[CurrentUser] MiniAppUser $user): JsonResponse
    {
        $locale = MiniAppLocale::fromRequest($request);
        try {
            $body = ResellerInput::body($request);
            [$planId, $trafficGb, $days] = [ResellerInput::id($body, 'planId'), ResellerInput::optionalNumber($body, 'trafficGb'), ResellerInput::optionalNumber($body, 'durationDays')];
        } catch (InvalidResellerInput $e) {
            return $this->invalidInput($e, $locale);
        }
        [$status, $answer] = $this->purchases->purchase($user, ResellerPurchases::KIND_NEW, $planId, $trafficGb, $days, $request->headers->get(CheckoutIdempotency::HEADER), $request->headers->get(AdminConfirmTokens::HEADER), $locale);

        return self::answer($answer, $status);
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
