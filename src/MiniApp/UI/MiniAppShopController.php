<?php

declare(strict_types=1);

namespace App\MiniApp\UI;

use App\Entity\Order;
use App\Entity\Plan;
use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\MiniAppCheckout;
use App\MiniApp\Security\MiniAppUser;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\NewServiceCheckout;
use App\Shop\Application\Checkout\OrderLifecycle;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Shop\Application\Checkout\PlanOptions;
use App\Shop\Application\Checkout\TrialCheckout;
use App\Shop\Application\SalesSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The shop of the Mini App: the plans for sale with their server-computed price, the customer's incomplete order
 * (resume / cancel), trial plans, and a plan's checkout form.
 */
#[IsGranted(MiniAppUser::ROLE)]
final class MiniAppShopController extends AbstractController
{
    public function __construct(
        private readonly MiniAppFragments $fragments,
        private readonly PlanCatalog $planCatalog,
        private readonly PlanOptions $planOptions,
        private readonly NewServiceCheckout $newServiceCheckout,
        private readonly OrderLifecycle $orderLifecycle,
        private readonly TrialCheckout $trialCheckout,
        private readonly SalesSettingsProvider $salesSettings,
        private readonly MiniAppCheckout $checkout,
    ) {
    }

    #[Route('/app/view/shop', name: 'miniapp_shop', defaults: ['_miniapp_section' => 'shop'], methods: ['GET'])]
    public function shop(Request $request, #[CurrentUser] MiniAppUser $user): Response
    {
        $open = $this->salesSettings->newOrdersEnabled();
        $plans = [];
        foreach ($open ? $this->planCatalog->forSale() : [] as $plan) {
            $plans[] = ['plan' => $plan, 'price' => $this->defaultPrice($plan), 'customizable' => $this->planOptions->asksTraffic($plan) || $this->planOptions->asksDuration($plan)];
        }

        return $this->fragments->render($request, 'miniapp/view/shop.html.twig', [
            'salesOpen' => $open,
            'disabledMessage' => $this->salesSettings->disabledMessage(),
            'plans' => $plans,
            'incomplete' => $this->incomplete($user),
            'trialPlans' => $this->trialCheckout->activePlans(),
        ]);
    }

    #[Route('/app/view/shop/plans/{id}', name: 'miniapp_plan', requirements: ['id' => '\d+'], defaults: ['_miniapp_section' => 'shop'], methods: ['GET'])]
    public function plan(Request $request, #[CurrentUser] MiniAppUser $user, int $id): Response
    {
        try {
            $plan = $this->checkout->plan($id);
            $quote = $this->checkout->quoteNewService($user->customer(), $id, $this->planOptions->defaultTrafficGb($plan), $this->planOptions->defaultDurationDays($plan), null);
        } catch (CheckoutFailure) {
            throw new NotFoundHttpException();
        }

        return $this->fragments->render($request, 'miniapp/view/plan.html.twig', [
            'plan' => $plan,
            'quote' => $quote,
            'salesOpen' => $this->salesSettings->newOrdersEnabled(),
            'disabledMessage' => $this->salesSettings->disabledMessage(),
            'asksTraffic' => $this->planOptions->asksTraffic($plan),
            'asksDuration' => $this->planOptions->asksDuration($plan),
            'trafficGb' => $this->planOptions->defaultTrafficGb($plan),
            'durationDays' => $this->planOptions->defaultDurationDays($plan),
        ]);
    }

    private function defaultPrice(Plan $plan): int
    {
        return $this->newServiceCheckout->quote($plan, $this->planOptions->defaultTrafficGb($plan), $this->planOptions->defaultDurationDays($plan))->afterGlobalDiscountAmount;
    }

    /**
     * The incomplete order and where resuming it leads: its pending payment, else the choice of a method.
     *
     * @return array{order: Order, href: string}|null
     */
    private function incomplete(MiniAppUser $user): ?array
    {
        $order = $this->orderLifecycle->incompleteOrder($user->customer());
        if (null === $order) {
            return null;
        }
        $payment = $this->orderLifecycle->latestPayment($order);
        $href = null !== $payment && PaymentStatus::PENDING === $payment->getStatus()
            ? $this->generateUrl('miniapp_payment', ['id' => $payment->getId()])
            : $this->generateUrl('miniapp_order_pay', ['id' => $order->getId()]);

        return ['order' => $order, 'href' => $href];
    }
}
