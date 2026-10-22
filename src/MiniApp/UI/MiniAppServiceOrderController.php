<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\MiniAppCheckout;
use App\MiniApp\Security\MiniAppUser;
use App\Provisioning\Application\CustomerServiceFinder;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Shop\Application\Checkout\ServiceOrderCheckout;
use App\Shop\Application\SalesSettingsProvider;
use App\Subscription\Application\SubscriptionPageViewFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Renewing a service and buying extra traffic for it: the forms (the server's quote, a discount code); the orders
 * are created by MiniAppCheckoutController and paid like any other.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppServiceOrderController extends AbstractController
{
    public function __construct(
        private readonly MiniAppFragments $fragments,
        private readonly MiniAppCheckout $checkout,
        private readonly CustomerServiceFinder $customerServices,
        private readonly ServiceOrderCheckout $serviceOrderCheckout,
        private readonly SubscriptionPageViewFactory $viewFactory,
        private readonly SalesSettingsProvider $salesSettings,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettings,
    ) {
    }

    #[Route('/app/view/services/{id}/renew', name: 'miniapp_service_renew', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]
    public function renew(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $service = $this->customerServices->ownedBy($user->customer(), $id) ?? throw new NotFoundHttpException();
        $renewal = $this->serviceOrderCheckout->renewalQuote($service);
        try {
            $quote = null !== $renewal ? $this->checkout->quoteRenewal($user->customer(), $id, null) : null;
        } catch (CheckoutFailure) {
            $quote = null;
        }

        return $this->fragments->render($request, 'miniapp/view/renew.html.twig', [
            'service' => $service,
            'view' => $this->viewFactory->summary($service),
            'renewal' => $renewal,
            'quote' => $quote,
            'open' => $this->salesSettings->renewalsEnabled(),
            'disabledMessage' => $this->salesSettings->disabledMessage(),
        ]);
    }

    #[Route('/app/view/services/{id}/add-traffic', name: 'miniapp_service_add_traffic', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'services'], methods: ['GET'])]
    public function addTraffic(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        $service = $this->customerServices->ownedBy($user->customer(), $id) ?? throw new NotFoundHttpException();
        $minGb = $this->trafficAddonSettings->minGb();
        try {
            $quote = $this->checkout->quoteAddTraffic($user->customer(), $id, $minGb, null);
        } catch (CheckoutFailure) {
            $quote = null;
        }

        return $this->fragments->render($request, 'miniapp/view/add_traffic.html.twig', [
            'service' => $service,
            'view' => $this->viewFactory->summary($service),
            'quote' => $quote,
            'open' => $this->salesSettings->addTrafficEnabled() && $this->trafficAddonSettings->canPurchase(),
            'disabledMessage' => $this->salesSettings->disabledMessage(),
            'minGb' => $minGb,
            'maxGb' => $this->trafficAddonSettings->maxGb(),
            'pricePerGb' => $this->trafficAddonSettings->pricePerGb(),
        ]);
    }
}
