<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\Reseller;
use App\Entity\User;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\SettlementResult;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Domain\ResellerException;
use App\Reseller\Domain\ResellerStatus;
use App\Reseller\Domain\WholesaleQuote;
use App\Shared\Infrastructure\TransactionRollback;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Shop\Application\Checkout\PlanOptions;
use App\Shop\Application\OrderTrackingCodeService;
use App\Shop\Application\SalesSettingsProvider;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Domain\WalletException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A reseller's purchase at wholesale: a new service, a renewal or extra traffic for a service he sold. Shared by the
 * bot and (later) the Mini App; the client sends ids and options, never an amount.
 *
 * Wallet only, created and paid in one call (docs/design/reseller-and-claim.md, 2.3 / 2.4):
 *
 * 1. One transaction, under the reseller row's lock: the reseller is still active (and reseller.enabled on), the
 *    daily cap is not exceeded (it counts this reseller's open and paid orders of today, so two parallel purchases
 *    are serialized here and the second sees the first), the price is quoted now (ResellerPricing) and the order is
 *    created with that snapshot, and WalletPayment::open() creates its wallet payment and debits it
 *    (purchase:payment:{id}). Either all of it exists or none of it: when the wallet does not cover it nothing stays
 *    behind, not even an unpaid order.
 * 2. WalletPayment::pay() settles that payment through PaymentSettlementService as any wallet payment: provisioning,
 *    PROVISIONING_FAILED + retry, receipts and audit are the existing ones. The snapshot on the order is what is paid.
 *
 * Lock order: reseller, then (inside open()) order, payment, user, wallet: the order settlement already uses after
 * the order, so this never deadlocks with it; disabling a reseller takes the same reseller lock first.
 *
 * Discount codes never apply to reseller orders (discount_not_for_resellers); the global discount never stacks either
 * (the wholesale price starts from the base price, ResellerPricing).
 */
class ResellerCheckout
{
    /** The order statuses that no longer count towards the daily cap (nothing was or will be paid). */
    private const CLOSED_STATUSES = [OrderStatus::CANCELLED, OrderStatus::EXPIRED, OrderStatus::FAILED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResellerSettings $settings,
        private readonly ResellerPricing $pricing,
        private readonly WalletPayment $walletPayment,
        private readonly PlanCatalog $planCatalog,
        private readonly PlanOptions $planOptions,
        private readonly SalesSettingsProvider $salesSettings,
        private readonly TrafficAddonSettingsProvider $trafficAddonSettings,
        private readonly RenewalSettingsProvider $renewalSettings,
        private readonly OrderTrackingCodeService $trackingCodes,
        private readonly TransactionRollback $transactionRollback,
    ) {
    }

    /** The user's reseller profile when it may buy now (reseller.enabled on, profile active), else null. */
    public function resellerFor(User $user): ?Reseller
    {
        if (!$this->settings->enabled() || null === $user->getId()) {
            return null;
        }
        $reseller = $this->entityManager->getRepository(Reseller::class)->findOneBy(['user' => $user]);

        return $reseller instanceof Reseller && $reseller->isActive() ? $reseller : null;
    }

    /**
     * What a new service of this plan costs the reseller now (for the confirmation step; nothing is written).
     *
     * @return array{Reseller, Plan, WholesaleQuote}
     *
     * @throws ResellerException reseller_unavailable, sales_closed, plan_unavailable, invalid_options, price_unavailable,
     *                           discount_not_for_resellers
     */
    public function quoteNewService(User $user, int $planId, ?int $trafficGb = null, ?int $durationDays = null, ?string $discountCode = null): array
    {
        self::refuseDiscountCode($discountCode);
        $reseller = $this->resellerFor($user) ?? throw new ResellerException('reseller_unavailable');
        if (!$this->salesSettings->newOrdersEnabled()) {
            throw new ResellerException('sales_closed');
        }
        $plan = $this->planCatalog->findForSale($planId) ?? throw new ResellerException('plan_unavailable');
        [$trafficGb, $durationDays] = $this->options($plan, $trafficGb, $durationDays);

        return [$reseller, $plan, $this->pricing->quoteNewService($reseller, $plan, $trafficGb, $durationDays)];
    }

    /**
     * Buys a new service at wholesale from the reseller's wallet.
     *
     * @throws ResellerException see quoteNewService(), and daily_limit_reached
     * @throws WalletException   wallet_payment_disabled, wallet_frozen, wallet_insufficient: nothing was written
     */
    public function buyNewService(User $user, int $planId, ?int $trafficGb = null, ?int $durationDays = null, ?string $discountCode = null): SettlementResult
    {
        self::refuseDiscountCode($discountCode);

        return $this->purchase($user, function (Reseller $reseller) use ($planId, $trafficGb, $durationDays): Order {
            if (!$this->salesSettings->newOrdersEnabled()) {
                throw new ResellerException('sales_closed');
            }
            $plan = $this->planCatalog->findForSale($planId) ?? throw new ResellerException('plan_unavailable');
            [$traffic, $days] = $this->options($plan, $trafficGb, $durationDays);
            $quote = $this->pricing->quoteNewService($reseller, $plan, $traffic, $days);

            return (new Order())
                ->setUser($reseller->getUser())
                ->setPlan($plan)
                ->setType(OrderType::NEW_SERVICE)
                ->setAmount($quote->wholesaleAmount)
                ->setStatus(OrderStatus::WAITING_PAYMENT)
                ->attributeToReseller($reseller, $quote->wholesaleAmount, $quote->retailAmount, $quote->floorAmount)
                ->setMetadata([
                    'custom' => $plan->isCustomizable(),
                    'trafficGb' => $traffic ?? $plan->getTrafficGb(),
                    'durationDays' => $days ?? $plan->getDurationDays(),
                    'unlimitedDuration' => $plan->isUnlimitedDuration(),
                    'calculatedAmount' => $quote->wholesaleAmount,
                    'priceSnapshot' => self::priceSnapshot($quote, $reseller),
                    'orderType' => OrderType::NEW_SERVICE,
                    'resellerOrder' => true,
                ]);
        });
    }

    /**
     * What renewing this service costs the reseller now (nothing is written).
     *
     * @return array{Reseller, VpnService, WholesaleQuote}
     *
     * @throws ResellerException reseller_unavailable, sales_closed, service_not_sold_by_you, price_unavailable
     */
    public function quoteRenewal(User $user, int $serviceId): array
    {
        $reseller = $this->resellerFor($user) ?? throw new ResellerException('reseller_unavailable');
        if (!$this->salesSettings->renewalsEnabled()) {
            throw new ResellerException('sales_closed');
        }
        $service = $this->soldService($reseller, $serviceId);

        return [$reseller, $service, $this->pricing->quoteRenewal($reseller, $service)[0]];
    }

    /**
     * Renews a service this reseller sold, at wholesale, from his wallet.
     *
     * @throws ResellerException see quoteRenewal(), discount_not_for_resellers, daily_limit_reached
     * @throws WalletException   see buyNewService()
     */
    public function renew(User $user, int $serviceId, ?string $discountCode = null): SettlementResult
    {
        self::refuseDiscountCode($discountCode);

        return $this->purchase($user, function (Reseller $reseller) use ($serviceId): Order {
            if (!$this->salesSettings->renewalsEnabled()) {
                throw new ResellerException('sales_closed');
            }
            $service = $this->soldService($reseller, $serviceId);
            [$quote, $renewal, $plan] = $this->pricing->quoteRenewal($reseller, $service);

            return (new Order())
                ->setUser($reseller->getUser())
                ->setPlan($plan)
                ->setTargetService($service)
                ->setType(OrderType::RENEWAL)
                ->setAmount($quote->wholesaleAmount)
                ->setStatus(OrderStatus::WAITING_PAYMENT)
                ->attributeToReseller($reseller, $quote->wholesaleAmount, $quote->retailAmount, $quote->floorAmount)
                ->setMetadata([
                    'targetServiceId' => $service->getId(),
                    'trafficGb' => $renewal->trafficGb,
                    'renewal' => true,
                    'durationDays' => $renewal->durationDays,
                    'unlimitedDuration' => $renewal->unlimitedDuration,
                    'renewalPolicy' => [
                        'carryRemainingTraffic' => $this->renewalSettings->carryRemainingTraffic(),
                        'carryRemainingDays' => $this->renewalSettings->carryRemainingDays(),
                    ],
                    'priceSnapshot' => self::priceSnapshot($quote, $reseller),
                    'resellerOrder' => true,
                ]);
        });
    }

    /**
     * What this much extra traffic costs the reseller now (nothing is written).
     *
     * @return array{Reseller, VpnService, WholesaleQuote}
     *
     * @throws ResellerException reseller_unavailable, sales_closed, invalid_options, service_not_sold_by_you, price_unavailable
     */
    public function quoteAddTraffic(User $user, int $serviceId, int $trafficGb): array
    {
        $reseller = $this->resellerFor($user) ?? throw new ResellerException('reseller_unavailable');
        $this->assertAddTrafficOpen($trafficGb);
        $service = $this->soldService($reseller, $serviceId);

        return [$reseller, $service, $this->pricing->quoteAddTraffic($reseller, $service, $trafficGb)];
    }

    /**
     * Adds traffic to a service this reseller sold, at wholesale, from his wallet.
     *
     * @throws ResellerException see quoteAddTraffic(), discount_not_for_resellers, daily_limit_reached
     * @throws WalletException   see buyNewService()
     */
    public function addTraffic(User $user, int $serviceId, int $trafficGb, ?string $discountCode = null): SettlementResult
    {
        self::refuseDiscountCode($discountCode);

        return $this->purchase($user, function (Reseller $reseller) use ($serviceId, $trafficGb): Order {
            $this->assertAddTrafficOpen($trafficGb);
            $service = $this->soldService($reseller, $serviceId);
            $quote = $this->pricing->quoteAddTraffic($reseller, $service, $trafficGb);
            $plan = $service->getOrder()?->getPlan() ?? throw new ResellerException('price_unavailable');

            return (new Order())
                ->setUser($reseller->getUser())
                ->setPlan($plan)
                ->setTargetService($service)
                ->setType(OrderType::ADD_TRAFFIC)
                ->setAmount($quote->wholesaleAmount)
                ->setStatus(OrderStatus::WAITING_PAYMENT)
                ->attributeToReseller($reseller, $quote->wholesaleAmount, $quote->retailAmount, $quote->floorAmount)
                ->setMetadata([
                    'targetServiceId' => $service->getId(),
                    'trafficGb' => $trafficGb,
                    'addTraffic' => true,
                    'priceSnapshot' => self::priceSnapshot($quote, $reseller),
                    'resellerOrder' => true,
                ]);
        });
    }

    /**
     * A live service this reseller sold, for his menu (read only).
     *
     * @throws ResellerException reseller_unavailable, service_not_sold_by_you
     */
    public function findSoldService(User $user, int $serviceId): VpnService
    {
        return $this->soldService($this->resellerFor($user) ?? throw new ResellerException('reseller_unavailable'), $serviceId);
    }

    /**
     * What this reseller still may spend today under his daily cap (null: no cap). Read only.
     */
    public function remainingToday(Reseller $reseller): ?int
    {
        $limit = $reseller->getDailySpendLimit();

        return null === $limit ? null : max(0, $limit - $this->spentToday($reseller));
    }

    /**
     * The services this reseller sold that are not deleted, newest first.
     *
     * @return array{items: list<VpnService>, hasNext: bool}
     */
    public function soldServices(Reseller $reseller, int $page, int $perPage): array
    {
        $page = max(1, min(10000, $page));
        /** @var list<VpnService> $rows */
        $rows = $this->entityManager->getRepository(VpnService::class)->createQueryBuilder('s')
            ->where('s.soldByReseller = :reseller')
            ->andWhere('s.status <> :deleted')
            ->setParameter('reseller', $reseller)
            ->setParameter('deleted', VpnServiceStatus::DELETED)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($perPage + 1)
            ->setFirstResult(($page - 1) * $perPage)
            ->getQuery()->getResult();

        return ['items' => array_slice($rows, 0, $perPage), 'hasNext' => count($rows) > $perPage];
    }

    /**
     * @param \Closure(Reseller): Order $build builds the (unpersisted) order under the reseller lock
     *
     * @throws ResellerException|WalletException nothing was written
     */
    private function purchase(User $user, \Closure $build): SettlementResult
    {
        if (null === $this->resellerFor($user)) {
            throw new ResellerException('reseller_unavailable');
        }
        $resellerId = (int) $this->entityManager->getRepository(Reseller::class)->findOneBy(['user' => $user])?->getId();

        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        $order = null;

        try {
            // The lock is this transaction's first statement: its snapshot (REPEATABLE READ starts it at the first plain
            // read) then begins after a parallel purchase of this reseller committed, so the daily cap below sees it.
            $connection->executeQuery('SELECT id FROM reseller WHERE id = ? FOR UPDATE', [$resellerId]);
            $reseller = $this->entityManager->find(Reseller::class, $resellerId) ?? throw new ResellerException('reseller_unavailable');
            $this->entityManager->refresh($reseller);
            if (!$this->settings->enabled() || ResellerStatus::ACTIVE !== $reseller->getStatus()) {
                throw new ResellerException('reseller_unavailable');
            }

            $order = $build($reseller);
            $limit = $reseller->getDailySpendLimit();
            if (null !== $limit && $this->spentToday($reseller) + $order->getAmount() > $limit) {
                throw new ResellerException('daily_limit_reached');
            }
            $this->trackingCodes->assignIfMissing($order);
            $this->entityManager->persist($order);
            $this->entityManager->flush();

            // Creates the wallet payment and debits it, inside this transaction (a savepoint).
            $this->walletPayment->open($order, true);
            $connection->commit();
        } catch (\Throwable $e) {
            if (!$this->transactionRollback->rollBack($level) || !$this->entityManager->isOpen()) {
                $this->transactionRollback->resetEntityManager();
            } elseif ($order instanceof Order && $this->entityManager->contains($order)) {
                // Rolled back: the order row does not exist.
                $this->entityManager->detach($order);
            }

            throw $e;
        }

        return $this->walletPayment->pay($order, true);
    }

    /** Today's wholesale of this reseller's orders that are open or paid (app timezone). */
    private function spentToday(Reseller $reseller): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM `order` WHERE reseller_id = ? AND created_at >= ? AND status NOT IN (?, ?, ?)',
            [$reseller->getId(), (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s'), ...self::CLOSED_STATUSES],
        );
    }

    /**
     * A live service this reseller sold (also after it changes owner later on).
     *
     * @throws ResellerException service_not_sold_by_you
     */
    private function soldService(Reseller $reseller, int $serviceId): VpnService
    {
        $service = $serviceId > 0 ? $this->entityManager->getRepository(VpnService::class)->find($serviceId) : null;
        if (!$service instanceof VpnService || $service->getSoldByReseller()?->getId() !== $reseller->getId() || VpnServiceStatus::DELETED === $service->getStatus() || null === $service->getOrder()?->getPlan()) {
            throw new ResellerException('service_not_sold_by_you');
        }

        return $service;
    }

    /**
     * @throws ResellerException sales_closed, invalid_options
     */
    private function assertAddTrafficOpen(int $trafficGb): void
    {
        if (!$this->salesSettings->addTrafficEnabled() || !$this->trafficAddonSettings->canPurchase()) {
            throw new ResellerException('sales_closed');
        }
        if ($trafficGb < $this->trafficAddonSettings->minGb() || $trafficGb > $this->trafficAddonSettings->maxGb()) {
            throw new ResellerException('invalid_options');
        }
    }

    /**
     * The plan's traffic / duration choice, by the customer dialogue's own rules (PlanOptions): asked values must be
     * valid, the others are the plan's.
     *
     * @return array{int|null, int|null}
     *
     * @throws ResellerException invalid_options
     */
    private function options(Plan $plan, ?int $trafficGb, ?int $durationDays): array
    {
        $traffic = $this->planOptions->asksTraffic($plan) ? $trafficGb : $this->planOptions->defaultTrafficGb($plan);
        $days = $this->planOptions->asksDuration($plan) ? $durationDays : $this->planOptions->defaultDurationDays($plan);
        if (($this->planOptions->asksTraffic($plan) && !$this->planOptions->isValidTraffic($plan, $traffic))
            || ($this->planOptions->asksDuration($plan) && !$this->planOptions->isValidDuration($plan, $days))) {
            throw new ResellerException('invalid_options');
        }

        return [$traffic, $days];
    }

    /**
     * The order's priceSnapshot: the fields every order has (finalAmount is what is paid, PaymentStarter::payableAmount)
     * plus the reseller block. No code and no global discount: a wholesale price never stacks with them.
     *
     * @return array<string, mixed>
     */
    private static function priceSnapshot(WholesaleQuote $quote, Reseller $reseller): array
    {
        return [
            'baseAmount' => $quote->baseAmount,
            'globalDiscountPercent' => 0,
            'globalDiscountAmount' => 0,
            'afterGlobalDiscountAmount' => $quote->wholesaleAmount,
            'discountCode' => null,
            'discountCodeAmount' => 0,
            'finalAmount' => $quote->wholesaleAmount,
            'planPriceSource' => 'reseller_wholesale',
            'reseller' => $quote->snapshot((int) $reseller->getId()),
        ];
    }

    /**
     * @throws ResellerException discount_not_for_resellers
     */
    private static function refuseDiscountCode(?string $discountCode): void
    {
        if (null !== $discountCode && '' !== trim($discountCode)) {
            throw new ResellerException('discount_not_for_resellers');
        }
    }
}
