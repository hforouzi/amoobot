<?php

declare(strict_types=1);

namespace App\MiniApp\Checkout;

use App\Bot\Application\TelegramChannelMembershipGate;
use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\RequiredChannel;
use App\Entity\User;
use App\Entity\VpnService;
use App\MiniApp\Security\MiniAppUser;
use App\Provisioning\Application\CustomerServiceFinder;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Shop\Application\Checkout\DiscountApplication;
use App\Shop\Application\Checkout\NewServiceCheckout;
use App\Shop\Application\Checkout\OrderLifecycle;
use App\Shop\Application\Checkout\PaymentStart;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Shop\Application\Checkout\PlanOptions;
use App\Shop\Application\Checkout\ServiceOrderCheckout;
use App\Shop\Application\Checkout\TrialCheckout;
use App\Shop\Application\DiscountCodeService;
use App\Shop\Application\PriceCalculationResult;
use App\Shop\Application\SalesSettingsProvider;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Mini App's checkout: each call takes the customer's choices at once and runs the same steps the bot runs one
 * message at a time (Shop\Application\Checkout), with the same rules: sales switches, plans for sale, the required
 * channels, the plan's traffic/duration range, discount codes checked by DiscountCodeService. Prices always come
 * from the server; nothing the client sends about a price is read. Every refusal is a CheckoutFailure.
 */
final class MiniAppCheckout
{
    /** The draft step recorded for drafts the Mini App creates (the bot never resumes them). */
    public const DRAFT_STEP = 'miniapp_checkout';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanCatalog $planCatalog,
        private readonly PlanOptions $planOptions,
        private readonly NewServiceCheckout $newServiceCheckout,
        private readonly ServiceOrderCheckout $serviceOrderCheckout,
        private readonly DiscountApplication $discountApplication,
        private readonly DiscountCodeService $discountCodeService,
        private readonly PaymentStarter $paymentStarter,
        private readonly OrderLifecycle $orderLifecycle,
        private readonly TrialCheckout $trialCheckout,
        private readonly SalesSettingsProvider $salesSettings,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettings,
        private readonly TelegramChannelMembershipGate $membershipGate,
        private readonly CustomerServiceFinder $customerServices,
    ) {
    }

    public function plan(int $planId): Plan
    {
        return $this->planCatalog->findForSale($planId) ?? throw new CheckoutFailure('plan_unavailable', 404);
    }

    public function quoteNewService(User $user, int $planId, mixed $trafficGb, mixed $durationDays, ?string $discountCode): CheckoutQuote
    {
        $plan = $this->plan($planId);
        [$trafficGb, $durationDays] = $this->options($plan, $trafficGb, $durationDays);

        return $this->quote($this->newServiceCheckout->quote($plan, $trafficGb, $durationDays), $user, OrderType::NEW_SERVICE, $plan, $discountCode);
    }

    public function newService(MiniAppUser $user, int $planId, string $accountName, mixed $trafficGb, mixed $durationDays, ?string $discountCode): Order
    {
        $this->requireOpen($this->salesSettings->newOrdersEnabled());
        $plan = $this->plan($planId);
        $this->requireChannels($user, TelegramChannelMembershipGate::CONTEXT_PURCHASE);
        if (null === $this->planOptions->normalizeAccountName($accountName)) {
            throw new CheckoutFailure('invalid_name', 422);
        }
        [$trafficGb, $durationDays] = $this->options($plan, $trafficGb, $durationDays);
        $this->requireUsableCode($this->quote($this->newServiceCheckout->quote($plan, $trafficGb, $durationDays), $user->customer(), OrderType::NEW_SERVICE, $plan, $discountCode));

        $draft = $this->newServiceCheckout->startDraft($user->customer(), $plan, self::DRAFT_STEP, false);
        $this->newServiceCheckout->setAccountName($draft, $accountName);
        $this->newServiceCheckout->price($draft, $trafficGb, $durationDays);
        $this->entityManager->flush();
        if (null !== $this->newServiceCheckout->confirmationBlocker($draft)) {
            throw new CheckoutFailure('plan_unavailable', 404);
        }
        $order = $this->newServiceCheckout->confirm($draft) ?? throw new CheckoutFailure('plan_unavailable', 404);
        $this->applyCode($order, $discountCode);

        return $order;
    }

    public function quoteRenewal(User $user, int $serviceId, ?string $discountCode): CheckoutQuote
    {
        $service = $this->service($user, $serviceId);
        $quote = $this->serviceOrderCheckout->renewalQuote($service) ?? throw new CheckoutFailure('renewal_unavailable', 409);
        $price = new PriceCalculationResult($quote->baseAmount, $quote->globalDiscountPercent, $quote->globalDiscountAmount, $quote->amount, $quote->amount);

        return $this->quote($price, $user, OrderType::RENEWAL, $quote->plan, $discountCode);
    }

    public function renewal(User $user, int $serviceId, ?string $discountCode): Order
    {
        $this->requireOpen($this->salesSettings->renewalsEnabled());
        $service = $this->service($user, $serviceId);
        $quote = $this->serviceOrderCheckout->renewalQuote($service) ?? throw new CheckoutFailure('renewal_unavailable', 409);
        $this->requireUsableCode($this->quoteRenewal($user, $serviceId, $discountCode));

        $draft = $this->serviceOrderCheckout->startRenewalDraft($service, $quote, false, self::DRAFT_STEP);
        if (null !== $discountCode && '' !== trim($discountCode)) {
            $this->requireApplied($this->discountApplication->applyToDraft($draft, $discountCode, OrderType::RENEWAL)->applied);
        }

        return $this->serviceOrderCheckout->createOrderFromDraft($draft) ?? throw new CheckoutFailure('renewal_unavailable', 409);
    }

    public function quoteAddTraffic(User $user, int $serviceId, mixed $trafficGb, ?string $discountCode): CheckoutQuote
    {
        $service = $this->service($user, $serviceId);
        $plan = $this->serviceOrderCheckout->addTrafficPlan($service) ?? throw new CheckoutFailure('add_traffic_unavailable', 409);
        $trafficGb = self::intOrNull($trafficGb);
        if (null === $trafficGb || !$this->serviceOrderCheckout->isValidAddTrafficAmount($trafficGb)) {
            throw new CheckoutFailure('invalid_traffic', 422);
        }
        $price = $this->serviceOrderCheckout->addTrafficQuote($trafficGb) ?? throw new CheckoutFailure('add_traffic_unavailable', 409);

        return $this->quote($price, $user, OrderType::ADD_TRAFFIC, $plan, $discountCode);
    }

    public function addTraffic(User $user, int $serviceId, mixed $trafficGb, ?string $discountCode): Order
    {
        $this->requireOpen($this->salesSettings->addTrafficEnabled() && $this->trafficAddonSettings->canPurchase());
        $service = $this->service($user, $serviceId);
        $this->requireUsableCode($this->quoteAddTraffic($user, $serviceId, $trafficGb, $discountCode));
        $trafficGb = (int) self::intOrNull($trafficGb);
        $plan = $this->serviceOrderCheckout->addTrafficPlan($service) ?? throw new CheckoutFailure('add_traffic_unavailable', 409);
        $price = $this->serviceOrderCheckout->addTrafficQuote($trafficGb) ?? throw new CheckoutFailure('add_traffic_unavailable', 409);

        $draft = $this->serviceOrderCheckout->startAddTrafficDraft($user, $service, $plan, self::DRAFT_STEP);
        $this->serviceOrderCheckout->setAddTrafficAmount($draft, $trafficGb, $price);
        $this->serviceOrderCheckout->confirmAddTraffic($draft, $service, $trafficGb, $price, self::DRAFT_STEP);
        if (null !== $discountCode && '' !== trim($discountCode)) {
            $this->requireApplied($this->discountApplication->applyToDraft($draft, $discountCode, OrderType::ADD_TRAFFIC)->applied);
        }

        return $this->serviceOrderCheckout->createOrderFromDraft($draft) ?? throw new CheckoutFailure('add_traffic_unavailable', 409);
    }

    /** The customer's order while it can still be paid. */
    public function payableOrder(User $user, int $orderId): Order
    {
        $order = $this->orderLifecycle->editableOrder($user, $orderId) ?? throw new CheckoutFailure('order_not_found', 404);
        if (OrderStatus::WAITING_PAYMENT !== $order->getStatus()) {
            throw new CheckoutFailure('order_not_payable', 409);
        }

        return $order;
    }

    /**
     * Starts the payment with the chosen store method; the gateway's refusal is part of the result (the payment row
     * keeps the gateway's note, as in the bot), not an exception.
     */
    public function pay(MiniAppUser $user, int $orderId, int $methodId): PaymentStart
    {
        $order = $this->payableOrder($user->customer(), $orderId);
        if (OrderType::NEW_SERVICE === $order->getType()) {
            $this->requireChannels($user, TelegramChannelMembershipGate::CONTEXT_PURCHASE);
        }
        $method = $this->paymentStarter->methodFor($order, $methodId) ?? throw new CheckoutFailure('method_invalid', 422);
        $start = $this->paymentStarter->start($order, $method);
        if (PaymentStart::KIND_PLAN_INACTIVE === $start->kind) {
            throw new CheckoutFailure('plan_unavailable', 404);
        }

        return $start;
    }

    /**
     * @return string OrderLifecycle::CANCEL_*
     */
    public function cancel(User $user, int $orderId): string
    {
        $order = $this->orderLifecycle->ownedOrder($user, $orderId) ?? throw new CheckoutFailure('order_not_found', 404);

        return $this->orderLifecycle->cancel($order);
    }

    public function claimTrial(MiniAppUser $user, int $trialPlanId): VpnService
    {
        $trialPlan = $this->trialCheckout->activePlan($trialPlanId) ?? throw new CheckoutFailure('trial_unavailable', 404);
        $this->requireChannels($user, TelegramChannelMembershipGate::CONTEXT_TRIAL);
        $result = $this->trialCheckout->claim($user->account, $trialPlan);
        if (!$result->isSuccess() || !$result->vpnService instanceof VpnService) {
            throw new CheckoutFailure('trial_refused', 422, $result->message);
        }

        return $result->vpnService;
    }

    /**
     * @return array{0: ?int, 1: ?int} traffic and duration: the customer's where the plan asks, else the plan's
     */
    private function options(Plan $plan, mixed $trafficGb, mixed $durationDays): array
    {
        if ($this->planOptions->asksTraffic($plan)) {
            $trafficGb = self::intOrNull($trafficGb);
            if (!$this->planOptions->isValidTraffic($plan, $trafficGb)) {
                throw new CheckoutFailure('invalid_traffic', 422);
            }
        } else {
            $trafficGb = $this->planOptions->defaultTrafficGb($plan);
        }

        if ($this->planOptions->asksDuration($plan)) {
            $durationDays = self::intOrNull($durationDays);
            if (!$this->planOptions->isValidDuration($plan, $durationDays)) {
                throw new CheckoutFailure('invalid_duration', 422);
            }
        } else {
            $durationDays = $this->planOptions->defaultDurationDays($plan);
        }

        return [$trafficGb, $durationDays];
    }

    private function quote(PriceCalculationResult $price, User $user, string $orderType, ?Plan $plan, ?string $discountCode): CheckoutQuote
    {
        $amount = $price->afterGlobalDiscountAmount;
        $code = trim((string) $discountCode);
        if ('' === $code) {
            return new CheckoutQuote($price->baseAmount, $price->globalDiscountAmount, $amount, null, false, 0, $amount, null);
        }

        $result = $this->discountCodeService->validateCode($code, $user, $orderType, $plan, $amount);
        if (!$result->valid || null === $result->discountCode) {
            return new CheckoutQuote($price->baseAmount, $price->globalDiscountAmount, $amount, $code, false, 0, $amount, $result->message);
        }

        return new CheckoutQuote($price->baseAmount, $price->globalDiscountAmount, $amount, $result->discountCode->getCode(), true, $result->discountAmount, $result->finalAmount, null);
    }

    private function applyCode(Order $order, ?string $discountCode): void
    {
        if (null === $discountCode || '' === trim($discountCode)) {
            return;
        }
        $this->requireApplied($this->discountApplication->applyToOrder($order, $discountCode)->applied);
    }

    private function requireUsableCode(CheckoutQuote $quote): void
    {
        if (null !== $quote->discountCode && !$quote->discountApplied) {
            throw new CheckoutFailure('discount_invalid', 422, $quote->discountMessage);
        }
    }

    private function requireApplied(bool $applied): void
    {
        if (!$applied) {
            throw new CheckoutFailure('discount_invalid', 422);
        }
    }

    private function requireOpen(bool $open): void
    {
        if (!$open) {
            throw new CheckoutFailure('sales_closed', 409, $this->salesSettings->disabledMessage());
        }
    }

    private function requireChannels(MiniAppUser $user, string $context): void
    {
        $result = $this->membershipGate->check($user->telegramUserId(), $context);
        if ($result->allowed) {
            return;
        }

        throw new CheckoutFailure('membership_required', 403, null, ['channels' => array_map(static fn (RequiredChannel $channel): array => [
            'title' => $channel->getTitle(),
            'url' => $channel->getInviteUrl() ?? (str_starts_with($channel->getChatId(), '@') ? 'https://t.me/'.substr($channel->getChatId(), 1) : null),
        ], $result->missingChannels)]);
    }

    private function service(User $user, int $serviceId): VpnService
    {
        return $this->customerServices->ownedBy($user, $serviceId) ?? throw new CheckoutFailure('service_not_found', 404);
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match('/^\d{1,9}$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }
}
