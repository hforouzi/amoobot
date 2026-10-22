<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Plan;
use App\Entity\User;
use App\Shop\Application\IncompleteOrderSettingsProvider;
use App\Shop\Application\OrderTrackingCodeService;
use App\Shop\Application\PlanPricingService;
use App\Shop\Application\PriceCalculationResult;
use App\Shop\Domain\OrderDraftStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Buying a new service: an OrderDraft collects the choices (account name, traffic, duration), its price is computed
 * here from the plan, and confirming it creates the order (WAITING_PAYMENT, price snapshot in the metadata). The bot
 * walks the customer through the draft step by step; the Mini App sends the choices at once (checkout()).
 */
final class NewServiceCheckout
{
    public const DRAFT_TYPE = 'new_service';
    public const CONFIRM_NOT_PENDING = 'not_pending';
    public const CONFIRM_EXPIRED = 'expired';
    public const CONFIRM_INCOMPLETE = 'incomplete';

    /** Draft data keys that belong to an earlier attempt and are dropped when a draft is (re)started. */
    private const STALE_DATA_KEYS = ['navigation', 'draftType', 'orderType', 'targetServiceId', 'adminMode', 'inputState', 'priceSnapshot'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanPricingService $planPricingService,
        private readonly OrderTrackingCodeService $orderTrackingCodeService,
        private readonly IncompleteOrderSettingsProvider $incompleteOrderSettingsProvider,
        private readonly PlanOptions $planOptions,
    ) {
    }

    /** When a draft started now stops being resumable. */
    public function draftExpiresAt(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d hours', $this->incompleteOrderSettingsProvider->expireHours()));
    }

    public function expireDrafts(User $user): void
    {
        /** @var list<OrderDraft> $drafts */
        $drafts = $this->entityManager->getRepository(OrderDraft::class)
            ->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.status = :status')
            ->andWhere('d.expiresAt IS NOT NULL AND d.expiresAt <= :now')
            ->setParameter('user', $user)
            ->setParameter('status', OrderDraftStatus::PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();

        if ([] === $drafts) {
            return;
        }

        foreach ($drafts as $draft) {
            $draft->setStatus(OrderDraftStatus::EXPIRED)->setUpdatedAt(new \DateTimeImmutable());
        }
        $this->entityManager->flush();
    }

    /** The customer's latest pending, unexpired draft (expired ones are marked expired first). */
    public function activeDraft(User $user): ?OrderDraft
    {
        $this->expireDrafts($user);

        return $this->entityManager->getRepository(OrderDraft::class)
            ->createQueryBuilder('d')
            ->where('d.user = :user')
            ->andWhere('d.status = :status')
            ->andWhere('d.expiresAt IS NULL OR d.expiresAt > :now')
            ->setParameter('user', $user)
            ->setParameter('status', OrderDraftStatus::PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('d.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Starts the draft for this plan, or restarts the customer's pending draft of the same plan with every choice
     * cleared. Persisted, not flushed (the caller records its own step first).
     *
     * @param string $step         the first step to record
     * @param bool   $reusePending false: always a new draft (the Mini App, which never takes over a bot conversation)
     */
    public function startDraft(User $user, Plan $plan, string $step, bool $reusePending = true): OrderDraft
    {
        $this->expireDrafts($user);

        $draft = $reusePending ? $this->activeDraft($user) : null;
        if (!$draft instanceof OrderDraft || $draft->getPlan()->getId() !== $plan->getId()) {
            $draft = (new OrderDraft())
                ->setUser($user)
                ->setPlan($plan)
                ->setStatus(OrderDraftStatus::PENDING)
                ->setStep($step)
                ->setExpiresAt($this->draftExpiresAt());
            $this->entityManager->persist($draft);
        }

        $draftData = is_array($draft->getData()) ? $draft->getData() : [];
        foreach (self::STALE_DATA_KEYS as $key) {
            unset($draftData[$key]);
        }

        return $draft
            ->setStatus(OrderDraftStatus::PENDING)
            ->setStep($step)
            ->setCustomUsernamePrefix(null)
            ->setFinalUsername(null)
            ->setTrafficGb(null)
            ->setDurationDays(null)
            ->setCalculatedAmount(null)
            ->setDiscountCode(null)
            ->setDiscountAmount(null)
            ->setFinalAmount(null)
            ->setPriceSnapshot(null)
            ->setUpdatedAt(new \DateTimeImmutable())
            ->setData($draftData);
    }

    /**
     * @return string|null the normalised name, null when it is not a valid account name (nothing is changed then)
     */
    public function setAccountName(OrderDraft $draft, string $name): ?string
    {
        $prefix = $this->planOptions->normalizeAccountName($name);
        if (null === $prefix) {
            return null;
        }
        $draft
            ->setCustomUsernamePrefix($prefix)
            ->setFinalUsername($this->planOptions->finalUsername($prefix))
            ->setUpdatedAt(new \DateTimeImmutable());

        return $prefix;
    }

    /** Fixes traffic and duration and stores the resulting amount on the draft (not flushed). */
    public function price(OrderDraft $draft, ?int $trafficGb, ?int $durationDays): int
    {
        $amount = $this->planPricingService->calculateNewOrderAmount($draft->getPlan(), [
            'trafficGb' => $trafficGb,
            'durationDays' => $durationDays,
        ]);
        $draft
            ->setTrafficGb($trafficGb)
            ->setDurationDays($durationDays)
            ->setCalculatedAmount($amount)
            ->setUpdatedAt(new \DateTimeImmutable());

        return $amount;
    }

    public function quote(Plan $plan, ?int $trafficGb, ?int $durationDays): PriceCalculationResult
    {
        return $this->planPricingService->calculateNewOrderPrice($plan, [
            'trafficGb' => $trafficGb,
            'durationDays' => $durationDays,
        ]);
    }

    /**
     * Why the draft cannot be confirmed (CONFIRM_*), or null. An expired draft is marked expired.
     */
    public function confirmationBlocker(OrderDraft $draft): ?string
    {
        if (OrderDraftStatus::PENDING !== $draft->getStatus()) {
            return self::CONFIRM_NOT_PENDING;
        }

        if (null !== $draft->getExpiresAt() && $draft->getExpiresAt() < new \DateTimeImmutable()) {
            $draft->setStatus(OrderDraftStatus::EXPIRED)->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            return self::CONFIRM_EXPIRED;
        }

        if (null === $draft->getCalculatedAmount() || $draft->getCalculatedAmount() <= 0 || null === $draft->getFinalUsername()) {
            return self::CONFIRM_INCOMPLETE;
        }

        return null;
    }

    /**
     * Prices the draft from the plan as it is now (the client never supplies a price), records the price snapshot
     * without a discount code and creates the order. Null when the draft cannot become an order.
     */
    public function confirm(OrderDraft $draft): ?Order
    {
        $price = $this->quote($draft->getPlan(), $draft->getTrafficGb(), $draft->getDurationDays());
        $draft
            ->setCalculatedAmount($price->afterGlobalDiscountAmount)
            ->setDiscountCode(null)
            ->setDiscountAmount(0)
            ->setFinalAmount($price->afterGlobalDiscountAmount)
            ->setPriceSnapshot([
                'baseAmount' => $price->baseAmount,
                'globalDiscountPercent' => $price->globalDiscountPercent,
                'globalDiscountAmount' => $price->globalDiscountAmount,
                'afterGlobalDiscountAmount' => $price->afterGlobalDiscountAmount,
                'discountCode' => null,
                'discountCodeAmount' => 0,
                'finalAmount' => $price->afterGlobalDiscountAmount,
                'planPriceSource' => 'current_plan',
            ])
            ->setData(array_merge((array) ($draft->getData() ?? []), [
                'draftType' => self::DRAFT_TYPE,
                'orderType' => OrderType::NEW_SERVICE,
            ]))
            ->setUpdatedAt(new \DateTimeImmutable());

        return $this->createOrderFromDraft($draft);
    }

    /** The open new-service order a draft already became, if any. */
    public function openOrderForDraft(OrderDraft $draft): ?Order
    {
        if (null === $draft->getId()) {
            return null;
        }

        $orders = $this->entityManager->getRepository(Order::class)->findBy(
            ['user' => $draft->getUser(), 'type' => OrderType::NEW_SERVICE],
            ['id' => 'DESC'],
            50
        );

        foreach ($orders as $order) {
            if (!in_array($order->getStatus(), [OrderStatus::WAITING_PAYMENT, OrderStatus::PAYMENT_PENDING, OrderStatus::PENDING, OrderStatus::DRAFT], true)) {
                continue;
            }
            $metadata = is_array($order->getMetadata()) ? $order->getMetadata() : [];
            if ((int) ($metadata['orderDraftId'] ?? 0) === (int) $draft->getId()) {
                return $order;
            }
        }

        return null;
    }

    /**
     * The order of a confirmed draft: the open one it already became, or a new WAITING_PAYMENT order with the
     * draft's choices and price snapshot (the draft becomes CONFIRMED). Null for a draft that is not pending or
     * whose plan was switched off.
     */
    public function createOrderFromDraft(OrderDraft $draft): ?Order
    {
        $existingOrder = $this->openOrderForDraft($draft);
        if ($existingOrder instanceof Order) {
            return $existingOrder;
        }

        if (OrderDraftStatus::PENDING !== $draft->getStatus()) {
            return null;
        }

        $plan = $draft->getPlan();
        if (!$plan->isActive()) {
            return null;
        }

        $finalAmount = max(0, (int) ($draft->getFinalAmount() ?? $draft->getCalculatedAmount() ?? 0));
        $priceSnapshot = is_array($draft->getPriceSnapshot()) ? $draft->getPriceSnapshot() : [];
        $metadata = [
            'custom' => true,
            'customUsername' => $draft->getCustomUsernamePrefix(),
            'customUsernamePrefix' => $draft->getCustomUsernamePrefix(),
            'finalUsername' => $draft->getFinalUsername(),
            'trafficGb' => $draft->getTrafficGb(),
            'durationDays' => $draft->getDurationDays(),
            'unlimitedDuration' => $plan->isUnlimitedDuration() || null === $draft->getDurationDays(),
            'calculatedAmount' => $finalAmount,
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
            'orderType' => OrderType::NEW_SERVICE,
            'draftStep' => (string) $draft->getStep(),
        ];

        $order = (new Order())
            ->setUser($draft->getUser())
            ->setPlan($plan)
            ->setAmount($finalAmount)
            ->setType(OrderType::NEW_SERVICE)
            ->setMetadata($metadata)
            ->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->orderTrackingCodeService->assignIfMissing($order);

        $draft
            ->setStatus(OrderDraftStatus::CONFIRMED)
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
