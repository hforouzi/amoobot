<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Plan;
use App\Entity\User;
use App\Entity\VpnService;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Shop\Application\OrderTrackingCodeService;
use App\Shop\Application\PlanPricingService;
use App\Shop\Application\PriceCalculationResult;
use App\Shop\Application\RenewalPriceResult;
use App\Shop\Application\TrafficAddonPricingService;
use App\Shop\Domain\OrderDraftStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Renewing a service and buying extra traffic for it: the price comes from the service's plan as it is now
 * (PlanPricingService::calculateRenewalAmount) or from the add-on price per GB (TrafficAddonPricingService), an
 * OrderDraft holds it while a discount code is chosen, and the draft becomes a RENEWAL / ADD_TRAFFIC order that
 * targets the service.
 */
final class ServiceOrderCheckout
{
    public const DRAFT_RENEWAL = 'renewal';
    public const DRAFT_ADD_TRAFFIC = 'add_traffic';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanPricingService $planPricingService,
        private readonly TrafficAddonPricingService $trafficAddonPricingService,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettings,
        private readonly RenewalSettingsProvider $renewalSettings,
        private readonly OrderTrackingCodeService $orderTrackingCodeService,
        private readonly NewServiceCheckout $newServiceCheckout,
    ) {
    }

    /** What renewing costs, or null when the service has no plan to renew from or the price is zero. */
    public function renewalQuote(VpnService $service): ?RenewalQuote
    {
        $sourceOrder = $service->getOrder();
        if (!$sourceOrder instanceof Order) {
            return null;
        }

        $plan = $sourceOrder->getPlan();
        $priceResult = $this->planPricingService->calculateRenewalAmount($service, $plan);
        if (!$priceResult instanceof RenewalPriceResult || $priceResult->finalAmount <= 0) {
            return null;
        }

        return new RenewalQuote(
            plan: $plan,
            amount: $priceResult->finalAmount,
            trafficGb: $priceResult->trafficGb,
            durationDays: $priceResult->durationDays,
            unlimitedDuration: $priceResult->unlimitedDuration,
            baseAmount: $priceResult->baseAmount,
            globalDiscountPercent: $priceResult->globalDiscountPercent,
            globalDiscountAmount: $priceResult->globalDiscountAmount,
            afterGlobalDiscountAmount: $priceResult->afterGlobalDiscountAmount,
            carryRemainingTraffic: $this->renewalSettings->carryRemainingTraffic(),
            carryRemainingDays: $this->renewalSettings->carryRemainingDays(),
        );
    }

    /**
     * A pending renewal draft at the quoted price, without a discount code (flushed).
     *
     * @param string $step the step to record on the draft
     */
    public function startRenewalDraft(VpnService $service, RenewalQuote $quote, bool $adminMode, string $step): OrderDraft
    {
        $draft = (new OrderDraft())
            ->setUser($service->getUser())
            ->setPlan($quote->plan)
            ->setStatus(OrderDraftStatus::PENDING)
            ->setStep($step)
            ->setTrafficGb($quote->trafficGb)
            ->setDurationDays($quote->durationDays)
            ->setCalculatedAmount($quote->amount)
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount($quote->amount)
            ->setPriceSnapshot([
                'baseAmount' => $quote->baseAmount,
                'globalDiscountPercent' => $quote->globalDiscountPercent,
                'globalDiscountAmount' => $quote->globalDiscountAmount,
                'afterGlobalDiscountAmount' => $quote->amount,
                'discountCode' => null,
                'discountCodeAmount' => 0,
                'finalAmount' => $quote->amount,
                'planPriceSource' => 'current_plan',
            ])
            ->setData([
                'draftType' => self::DRAFT_RENEWAL,
                'orderType' => OrderType::RENEWAL,
                'targetServiceId' => $service->getId(),
                'trafficGb' => $quote->trafficGb,
                'durationDays' => $quote->durationDays,
                'unlimitedDuration' => $quote->unlimitedDuration,
                'renewalPolicy' => [
                    'carryRemainingTraffic' => $quote->carryRemainingTraffic,
                    'carryRemainingDays' => $quote->carryRemainingDays,
                ],
                'adminMode' => $adminMode,
            ])
            ->setExpiresAt($this->newServiceCheckout->draftExpiresAt())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    /** The plan extra traffic is sold under (the service's own order's plan), or null. */
    public function addTrafficPlan(VpnService $service): ?Plan
    {
        $plan = $service->getOrder()?->getPlan();

        return $plan instanceof Plan ? $plan : null;
    }

    /**
     * An empty add-traffic draft for the service, waiting for the amount (flushed).
     *
     * @param string $step the step to record on the draft
     */
    public function startAddTrafficDraft(User $user, VpnService $service, Plan $plan, string $step): OrderDraft
    {
        $draft = (new OrderDraft())
            ->setUser($user)
            ->setPlan($plan)
            ->setStatus(OrderDraftStatus::PENDING)
            ->setStep($step)
            ->setTrafficGb(null)
            ->setCalculatedAmount(null)
            ->setData([
                'draftType' => self::DRAFT_ADD_TRAFFIC,
                'targetServiceId' => $service->getId(),
            ])
            ->setExpiresAt($this->newServiceCheckout->draftExpiresAt())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return $draft;
    }

    /** Whether this many GB is within the add-on range. */
    public function isValidAddTrafficAmount(?int $trafficGb): bool
    {
        return null !== $trafficGb && $trafficGb >= $this->trafficAddonSettings->minGb() && $trafficGb <= $this->trafficAddonSettings->maxGb();
    }

    /** The price of this many GB, or null when extra traffic is not for sale at that price (zero). */
    public function addTrafficQuote(int $trafficGb): ?PriceCalculationResult
    {
        $price = $this->trafficAddonPricingService->calculate($trafficGb);

        return $price->finalAmount > 0 ? $price : null;
    }

    /** Stores the chosen amount and its price (no discount code yet) on the draft and flushes. */
    public function setAddTrafficAmount(OrderDraft $draft, int $trafficGb, PriceCalculationResult $price): void
    {
        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $priceSnapshot = [
            'baseAmount' => $price->baseAmount,
            'globalDiscountPercent' => $price->globalDiscountPercent,
            'globalDiscountAmount' => $price->globalDiscountAmount,
            'afterGlobalDiscountAmount' => $price->afterGlobalDiscountAmount,
            'discountCode' => null,
            'discountCodeAmount' => 0,
            'finalAmount' => $price->finalAmount,
        ];

        $draft
            ->setTrafficGb($trafficGb)
            ->setCalculatedAmount($price->finalAmount)
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount($price->finalAmount)
            ->setPriceSnapshot($priceSnapshot)
            ->setData(array_merge($data, ['priceSnapshot' => $priceSnapshot]))
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * Re-prices the draft from the add-on price now and readies it for a discount code (flushed).
     *
     * @param string $step the step to record on the draft
     */
    public function confirmAddTraffic(OrderDraft $draft, VpnService $service, int $trafficGb, PriceCalculationResult $price, string $step): void
    {
        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $draft
            ->setStep($step)
            ->setCalculatedAmount($price->finalAmount)
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount($price->finalAmount)
            ->setPriceSnapshot([
                'baseAmount' => $price->baseAmount,
                'globalDiscountPercent' => $price->globalDiscountPercent,
                'globalDiscountAmount' => $price->globalDiscountAmount,
                'afterGlobalDiscountAmount' => $price->afterGlobalDiscountAmount,
                'discountCode' => null,
                'discountCodeAmount' => 0,
                'finalAmount' => $price->finalAmount,
            ])
            ->setData(array_merge($data, [
                'orderType' => OrderType::ADD_TRAFFIC,
                'targetServiceId' => $service->getId(),
                'trafficGb' => $trafficGb,
            ]))
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * The RENEWAL / ADD_TRAFFIC order of a pending draft (WAITING_PAYMENT, targeting the service; the draft becomes
     * CONFIRMED). Null when the draft is of another kind or its service is gone.
     */
    public function createOrderFromDraft(OrderDraft $draft): ?Order
    {
        $data = is_array($draft->getData()) ? $draft->getData() : [];
        $draftType = (string) ($data['draftType'] ?? '');
        if (!in_array($draftType, [self::DRAFT_RENEWAL, self::DRAFT_ADD_TRAFFIC], true)) {
            return null;
        }

        $targetServiceId = (int) ($data['targetServiceId'] ?? 0);
        $targetService = $targetServiceId > 0 ? $this->entityManager->getRepository(VpnService::class)->find($targetServiceId) : null;
        if (!$targetService instanceof VpnService) {
            return null;
        }

        $priceSnapshot = is_array($draft->getPriceSnapshot()) ? $draft->getPriceSnapshot() : [];
        $finalAmount = max(0, (int) ($draft->getFinalAmount() ?? $draft->getCalculatedAmount() ?? 0));
        $metadata = [
            'targetServiceId' => $targetService->getId(),
            'trafficGb' => (int) ($data['trafficGb'] ?? $draft->getTrafficGb() ?? 0),
            'priceSnapshot' => [
                'baseAmount' => (int) ($priceSnapshot['baseAmount'] ?? 0),
                'globalDiscountPercent' => (int) ($priceSnapshot['globalDiscountPercent'] ?? 0),
                'globalDiscountAmount' => (int) ($priceSnapshot['globalDiscountAmount'] ?? 0),
                'afterGlobalDiscountAmount' => (int) ($priceSnapshot['afterGlobalDiscountAmount'] ?? $draft->getCalculatedAmount() ?? 0),
                'discountCode' => $draft->getDiscountCode(),
                'discountCodeAmount' => (int) ($draft->getDiscountAmount() ?? 0),
                'finalAmount' => $finalAmount,
                'planPriceSource' => (string) ($priceSnapshot['planPriceSource'] ?? 'current_plan'),
            ],
            'orderDraftId' => $draft->getId(),
        ];

        $orderType = self::DRAFT_RENEWAL === $draftType ? OrderType::RENEWAL : OrderType::ADD_TRAFFIC;
        if (OrderType::RENEWAL === $orderType) {
            $metadata['renewal'] = true;
            $metadata['durationDays'] = (int) ($data['durationDays'] ?? 0);
            $metadata['unlimitedDuration'] = (bool) ($data['unlimitedDuration'] ?? false);
            $metadata['renewalPolicy'] = is_array($data['renewalPolicy'] ?? null) ? $data['renewalPolicy'] : [];
        } else {
            $metadata['addTraffic'] = true;
        }

        $order = (new Order())
            ->setUser($draft->getUser())
            ->setPlan($draft->getPlan())
            ->setTargetService($targetService)
            ->setType($orderType)
            ->setAmount($finalAmount)
            ->setMetadata($metadata)
            ->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->orderTrackingCodeService->assignIfMissing($order);

        $draft->setStatus(OrderDraftStatus::CONFIRMED)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
