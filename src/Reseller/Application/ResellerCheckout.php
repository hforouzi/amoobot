<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\Reseller;
use App\Entity\User;
use App\Entity\VpnService;
use App\Kernel;
use App\Payment\Application\Settlement\SettlementResult;
use App\Provisioning\Application\RenewalSettingsProvider;
use App\Provisioning\Application\TrafficAddonSettingsProvider;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Domain\ResellerConfirmation;
use App\Reseller\Domain\ResellerException;
use App\Reseller\Domain\ResellerStatus;
use App\Reseller\Domain\WholesaleQuote;
use App\Shared\Infrastructure\TransactionRollback;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Shop\Application\Checkout\PlanOptions;
use App\Shop\Application\OrderTrackingCodeService;
use App\Shop\Application\SalesSettingsProvider;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Wallet\Application\WalletPayment;
use App\Wallet\Domain\WalletException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * A reseller's purchase at wholesale: a new service, a renewal or extra traffic for a service he sold. Shared by the
 * bot and (later) the Mini App; the client sends ids and options, never an amount.
 *
 * Wallet only, created and paid in one call (docs/design/reseller-and-claim.md, 2.3 / 2.4):
 *
 * 1. One transaction, under the locks of his user row and the reseller row: the reseller is still active (and reseller.enabled on), the
 *    daily cap is not exceeded (it counts this reseller's open and paid orders of today, so two parallel purchases
 *    are serialized here and the second sees the first), the price is quoted now (ResellerPricing) and the order is
 *    created with that snapshot, and WalletPayment::open() creates its wallet payment and debits it
 *    (purchase:payment:{id}). Either all of it exists or none of it: when the wallet does not cover it nothing stays
 *    behind, not even an unpaid order.
 * 2. WalletPayment::pay() settles that payment through PaymentSettlementService as any wallet payment: provisioning,
 *    PROVISIONING_FAILED + retry, receipts and audit are the existing ones. The snapshot on the order is what is paid.
 *
 * Lock order of everything that touches a reseller (the one place it is written down; ClaimLinks and the provisioning
 * of a sold service follow it): the reseller's USER row, then the RESELLER row, then orders, payments, the wallet.
 * - The purchase takes the user row (FOR UPDATE), then the reseller row (FOR UPDATE), then inside open() its own new
 *   order and payment rows (invisible to everyone else until commit) and the wallet (WalletService::lock(): the user
 *   row again, already held, then the wallet row).
 * - Provisioning a sold service inserts vpn_service, whose foreign key checks take the user row, then the reseller row
 *   (shared): the same order, so a purchase and the provisioning of the previous one wait for each other instead of
 *   deadlocking. (With the reseller row first, as before, they did: a purchase held the reseller row and waited for
 *   the user row that the provisioning's user_id check held while it waited for the reseller row.)
 * - A new claim link takes the user row, then the reseller row (both shared), then claim and service rows.
 * - Disabling a reseller takes only the reseller row, then his claim rows.
 * Should the database still choose this purchase as a deadlock or lock-wait victim (an unforeseen interleaving), the
 * transaction is rolled back completely and run again from the start, at most LOCK_ATTEMPTS times (busy after that,
 * nothing was taken). Only the order + debit transaction is retried; the settlement after it never is.
 *
 * Every purchase carries the reseller's confirmation (ResellerConfirmations: the amount he was shown and a one-time
 * nonce; the quote*() methods issue it). Under the lock: a nonce already on one of his orders is a second delivery of the
 * same tap (double tap, Telegram redelivery) and changes nothing (already_processed, with that order); an expired one
 * is refused (confirmation_expired); a price that differs from the confirmed one is refused (price_changed, with the
 * price now, for a fresh confirmation). The amount from the client is only compared, never charged. The nonce is stored
 * on the order (unique per reseller, the database's backstop).
 *
 * Discount codes never apply to reseller orders (discount_not_for_resellers); the global discount never stacks either
 * (the wholesale price starts from the base price, ResellerPricing).
 */
class ResellerCheckout
{
    public const STATE_NONE = 'none';
    public const STATE_HELD = 'held';
    public const STATE_DONE = 'done';
    public const STATE_FAILED = 'failed';
    public const STATE_RELEASED = 'released';

    /** The order statuses that no longer count towards the daily cap (nothing was or will be paid). */
    private const CLOSED_STATUSES = [OrderStatus::CANCELLED, OrderStatus::EXPIRED, OrderStatus::FAILED];
    /** Runs of the order + debit transaction when the database picked it as a deadlock / lock-wait victim. */
    public const LOCK_ATTEMPTS = 3;

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
        private readonly ResellerConfirmations $confirmations,
        private readonly PaymentStarter $paymentStarter,
        private readonly LoggerInterface $logger,
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

    public const OFFERS_SALES_CLOSED = 'sales_closed';
    public const OFFERS_NO_PLANS = 'no_plans';
    public const OFFERS_NOT_FOR_SALE = 'not_for_sale';
    public const OFFERS_NO_WHOLESALE = 'no_wholesale';

    /**
     * The plans this reseller can buy now, each with its wholesale price (for a customizable plan: its lowest choice, a
     * "from" price; the exact price is quoted once the traffic and days are chosen). When none, why (OFFERS_*): new
     * sales closed, no active plan configured at all, plans configured but none for sale (inactive inbound, degraded
     * panel), or plans for sale but none priceable for resellers. Nothing is written; no confirmation is issued.
     *
     * @return array{offers: list<array{plan: Plan, quote: WholesaleQuote, from: bool}>, reason: string|null}
     *
     * @throws ResellerException reseller_unavailable
     */
    public function newServiceOffers(User $user): array
    {
        $reseller = $this->resellerFor($user) ?? throw new ResellerException('reseller_unavailable');
        if (!$this->salesSettings->newOrdersEnabled()) {
            return ['offers' => [], 'reason' => self::OFFERS_SALES_CLOSED];
        }
        $forSale = $this->planCatalog->forSale();
        if ([] === $forSale) {
            $configured = (int) $this->entityManager->getRepository(Plan::class)->count(['isActive' => true]);

            return ['offers' => [], 'reason' => 0 === $configured ? self::OFFERS_NO_PLANS : self::OFFERS_NOT_FOR_SALE];
        }
        $offers = [];
        foreach ($forSale as $plan) {
            $customizable = $this->planOptions->asksTraffic($plan) || $this->planOptions->asksDuration($plan);
            $traffic = $this->planOptions->asksTraffic($plan) ? $plan->getMinTrafficGb() : $this->planOptions->defaultTrafficGb($plan);
            $days = $this->planOptions->asksDuration($plan) ? $plan->getMinDurationDays() : $this->planOptions->defaultDurationDays($plan);
            try {
                $offers[] = ['plan' => $plan, 'quote' => $this->pricing->quoteNewService($reseller, $plan, $traffic, $days), 'from' => $customizable];
            } catch (ResellerException) {
                // Not priceable for resellers (zero price): not offered.
            }
        }

        return ['offers' => $offers, 'reason' => [] === $offers ? self::OFFERS_NO_WHOLESALE : null];
    }

    /**
     * What the reseller must choose for a new service of this plan: traffic and/or days within the plan's range
     * (PlanOptions, the customer dialogue's rules), or nothing for a fixed plan.
     *
     * @return array{asksTraffic: bool, asksDuration: bool, minTraffic: int|null, maxTraffic: int|null, minDays: int|null, maxDays: int|null}
     *
     * @throws ResellerException reseller_unavailable, sales_closed, plan_unavailable
     */
    public function planChoices(User $user, int $planId): array
    {
        $this->resellerFor($user) ?? throw new ResellerException('reseller_unavailable');
        if (!$this->salesSettings->newOrdersEnabled()) {
            throw new ResellerException('sales_closed');
        }
        $plan = $this->planCatalog->findForSale($planId) ?? throw new ResellerException('plan_unavailable');

        return [
            'asksTraffic' => $this->planOptions->asksTraffic($plan),
            'asksDuration' => $this->planOptions->asksDuration($plan),
            'minTraffic' => $plan->getMinTrafficGb(),
            'maxTraffic' => $plan->getMaxTrafficGb(),
            'minDays' => $plan->getMinDurationDays(),
            'maxDays' => $plan->getMaxDurationDays(),
        ];
    }

    /**
     * What a new service of this plan costs the reseller now, with the one-time confirmation of that price (for the
     * confirmation step; nothing is written).
     *
     * @return array{Reseller, Plan, WholesaleQuote, ResellerConfirmation}
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
        [$traffic, $days] = $this->options($plan, $trafficGb, $durationDays);
        $quote = $this->pricing->quoteNewService($reseller, $plan, $traffic, $days);

        // Signed over the request as the caller will send it again with the purchase (not the plan's defaults).
        return [$reseller, $plan, $quote, $this->confirmations->issue($reseller, ResellerConfirmations::KIND_NEW, (int) $plan->getId(), (int) $trafficGb, (int) $durationDays, $quote->wholesaleAmount)];
    }

    /**
     * Buys a new service at wholesale from the reseller's wallet, for the price he confirmed.
     *
     * @throws ResellerException see quoteNewService(), and daily_limit_reached, confirmation_invalid,
     *                           confirmation_expired, price_changed (with the price now), already_processed (with the order)
     * @throws WalletException   wallet_payment_disabled, wallet_frozen, wallet_insufficient: nothing was written
     */
    public function buyNewService(User $user, int $planId, ResellerConfirmation $confirmation, ?int $trafficGb = null, ?int $durationDays = null, ?string $discountCode = null): SettlementResult
    {
        self::refuseDiscountCode($discountCode);

        return $this->purchase($user, ResellerConfirmations::KIND_NEW, $planId, (int) $trafficGb, (int) $durationDays, $confirmation, function (Reseller $reseller) use ($planId, $trafficGb, $durationDays): array {
            if (!$this->salesSettings->newOrdersEnabled()) {
                throw new ResellerException('sales_closed');
            }
            $plan = $this->planCatalog->findForSale($planId) ?? throw new ResellerException('plan_unavailable');
            [$traffic, $days] = $this->options($plan, $trafficGb, $durationDays);
            $quote = $this->pricing->quoteNewService($reseller, $plan, $traffic, $days);

            return [$quote, (new Order())
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
                ])];
        });
    }

    /**
     * What renewing this service costs the reseller now, with its one-time confirmation (nothing is written).
     *
     * @return array{Reseller, VpnService, WholesaleQuote, ResellerConfirmation}
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
        $quote = $this->pricing->quoteRenewal($reseller, $service)[0];

        return [$reseller, $service, $quote, $this->confirmations->issue($reseller, ResellerConfirmations::KIND_RENEWAL, $serviceId, 0, 0, $quote->wholesaleAmount)];
    }

    /**
     * Renews a service this reseller sold, at wholesale, from his wallet, for the price he confirmed.
     *
     * @throws ResellerException see quoteRenewal(), discount_not_for_resellers, daily_limit_reached, and the
     *                           confirmation errors of buyNewService()
     * @throws WalletException   see buyNewService()
     */
    public function renew(User $user, int $serviceId, ResellerConfirmation $confirmation, ?string $discountCode = null): SettlementResult
    {
        self::refuseDiscountCode($discountCode);

        return $this->purchase($user, ResellerConfirmations::KIND_RENEWAL, $serviceId, 0, 0, $confirmation, function (Reseller $reseller) use ($serviceId): array {
            if (!$this->salesSettings->renewalsEnabled()) {
                throw new ResellerException('sales_closed');
            }
            $service = $this->soldService($reseller, $serviceId);
            [$quote, $renewal, $plan] = $this->pricing->quoteRenewal($reseller, $service);

            return [$quote, (new Order())
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
                ])];
        });
    }

    /**
     * What this much extra traffic costs the reseller now, with its one-time confirmation (nothing is written).
     *
     * @return array{Reseller, VpnService, WholesaleQuote, ResellerConfirmation}
     *
     * @throws ResellerException reseller_unavailable, sales_closed, invalid_options, service_not_sold_by_you, price_unavailable
     */
    public function quoteAddTraffic(User $user, int $serviceId, int $trafficGb): array
    {
        $reseller = $this->resellerFor($user) ?? throw new ResellerException('reseller_unavailable');
        $this->assertAddTrafficOpen($trafficGb);
        $service = $this->soldService($reseller, $serviceId);
        $quote = $this->pricing->quoteAddTraffic($reseller, $service, $trafficGb);

        return [$reseller, $service, $quote, $this->confirmations->issue($reseller, ResellerConfirmations::KIND_TRAFFIC, $serviceId, $trafficGb, 0, $quote->wholesaleAmount)];
    }

    /**
     * Adds traffic to a service this reseller sold, at wholesale, from his wallet, for the price he confirmed.
     *
     * @throws ResellerException see quoteAddTraffic(), discount_not_for_resellers, daily_limit_reached, and the
     *                           confirmation errors of buyNewService()
     * @throws WalletException   see buyNewService()
     */
    public function addTraffic(User $user, int $serviceId, int $trafficGb, ResellerConfirmation $confirmation, ?string $discountCode = null): SettlementResult
    {
        self::refuseDiscountCode($discountCode);

        return $this->purchase($user, ResellerConfirmations::KIND_TRAFFIC, $serviceId, $trafficGb, 0, $confirmation, function (Reseller $reseller) use ($serviceId, $trafficGb): array {
            $this->assertAddTrafficOpen($trafficGb);
            $service = $this->soldService($reseller, $serviceId);
            $quote = $this->pricing->quoteAddTraffic($reseller, $service, $trafficGb);
            $plan = $service->getOrder()?->getPlan() ?? throw new ResellerException('price_unavailable');

            return [$quote, (new Order())
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
                ])];
        });
    }

    /**
     * Where the purchase of this confirmation stands, read from the database now (for the reseller's message after a
     * failure or a second delivery): none (no order: nothing was taken), held (the wallet payment holds the money while
     * the order is being processed; an unfinished one is released by the incomplete-order expiry), done (provisioned),
     * released (the order closed without service; the money went back to the wallet) or failed (provisioning failed;
     * the money is held for the admin's retry).
     *
     * @return array{string, Order|null}
     */
    public function purchaseState(Reseller $reseller, string $nonce): array
    {
        $order = '' === $nonce ? null : $this->entityManager->getRepository(Order::class)->findOneBy(['reseller' => $reseller, 'resellerNonce' => $nonce]);
        if (!$order instanceof Order) {
            return [self::STATE_NONE, null];
        }

        return [$this->orderState($order), $order];
    }

    /** Where this reseller order stands now (see purchaseState()); read from the database. */
    public function orderState(Order $order): string
    {
        $this->entityManager->refresh($order);

        return match (true) {
            OrderStatus::PROVISIONED === $order->getStatus() => self::STATE_DONE,
            OrderStatus::PROVISIONING_FAILED === $order->getStatus() => self::STATE_FAILED,
            null !== $this->paymentStarter->heldWalletPayment($order) => self::STATE_HELD,
            default => self::STATE_RELEASED,
        };
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
     * @param \Closure(Reseller): array{WholesaleQuote, Order} $build quotes now and builds the (unpersisted) order, under
     *                                                              the reseller lock (again on a retried attempt)
     *
     * @throws ResellerException|WalletException nothing was written (busy: still a lock victim after LOCK_ATTEMPTS)
     */
    private function purchase(User $user, string $kind, int $targetId, int $trafficGb, int $durationDays, ResellerConfirmation $confirmation, \Closure $build): SettlementResult
    {
        if (null === $this->resellerFor($user)) {
            throw new ResellerException('reseller_unavailable');
        }
        $userId = (int) $user->getId();
        $resellerId = (int) $this->entityManager->getRepository(Reseller::class)->findOneBy(['user' => $user])?->getId();
        $outer = $this->entityManager->getConnection()->getTransactionNestingLevel();

        for ($attempt = 1; ; ++$attempt) {
            try {
                $order = $this->createPaidOrder($userId, $resellerId, $kind, $targetId, $trafficGb, $durationDays, $confirmation, $build);
                break;
            } catch (RetryableException $e) {
                // Rolled back completely: nothing of this attempt exists. Inside a caller's transaction the whole of it is
                // gone, so only the caller can decide.
                $this->logger->warning('Reseller purchase was a deadlock / lock-wait victim.', ['reseller_id' => $resellerId, 'kind' => $kind, 'attempt' => $attempt, 'error' => $e::class]);
                if (0 !== $outer) {
                    throw $e;
                }
                if ($attempt >= self::LOCK_ATTEMPTS) {
                    throw new ResellerException('busy');
                }
                usleep(random_int(20_000, 60_000) * $attempt);
            }
        }

        return $this->walletPayment->pay($order, true);
    }

    /**
     * The order + debit transaction of purchase(), run once (see the class comment for the lock order).
     *
     * @param \Closure(Reseller): array{WholesaleQuote, Order} $build
     *
     * @throws ResellerException|WalletException|RetryableException nothing was written
     */
    private function createPaidOrder(int $userId, int $resellerId, string $kind, int $targetId, int $trafficGb, int $durationDays, ResellerConfirmation $confirmation, \Closure $build): Order
    {
        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        $order = null;

        try {
            // The locks are this transaction's first statements, the user row before the reseller row (class comment).
            // Its snapshot (REPEATABLE READ starts it at the first plain read) then begins after a parallel purchase of
            // this reseller committed, so the daily cap below sees it.
            $connection->executeQuery('SELECT id FROM `user` WHERE id = ? FOR UPDATE', [$userId]);
            $connection->executeQuery('SELECT id FROM reseller WHERE id = ? FOR UPDATE', [$resellerId]);
            $reseller = $this->entityManager->find(Reseller::class, $resellerId) ?? throw new ResellerException('reseller_unavailable');
            $this->entityManager->refresh($reseller);
            if (!$this->settings->enabled() || ResellerStatus::ACTIVE !== $reseller->getStatus()) {
                throw new ResellerException('reseller_unavailable');
            }

            // The confirmation: ours and for exactly this purchase; used once (a second delivery finds the first order and
            // changes nothing); not expired; and the price now is the one the reseller confirmed.
            $this->confirmations->assertAuthentic($reseller, $kind, $targetId, $trafficGb, $durationDays, $confirmation);
            $first = $connection->fetchOne('SELECT id FROM `order` WHERE reseller_id = ? AND reseller_nonce = ?', [$resellerId, $confirmation->nonce]);
            if (false !== $first) {
                throw new ResellerException('already_processed', null, (int) $first);
            }
            $this->confirmations->assertFresh($confirmation);
            [$quote, $order] = $build($reseller);
            if ($quote->wholesaleAmount !== $confirmation->amount) {
                $order = null;
                throw new ResellerException('price_changed', $quote);
            }
            $order->setResellerNonce($confirmation->nonce);
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
            // The unique (reseller, nonce) key: another delivery of this confirmation got there first (a backstop: the
            // reseller lock already serializes them and the check above finds the first order).
            if ($e instanceof UniqueConstraintViolationException) {
                throw new ResellerException('already_processed');
            }

            throw $e;
        }

        return $order;
    }

    /**
     * Today's wholesale of this reseller's orders that are open or paid. "Today" is the day in the application timezone
     * (Kernel::TIMEZONE, UTC): the daily cap restarts at 00:00 UTC, the same zone order.created_at is stored in.
     */
    private function spentToday(Reseller $reseller): int
    {
        $dayStart = new \DateTimeImmutable('today', new \DateTimeZone(Kernel::TIMEZONE));

        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM `order` WHERE reseller_id = ? AND created_at >= ? AND status NOT IN (?, ?, ?)',
            [$reseller->getId(), $dayStart->format('Y-m-d H:i:s'), ...self::CLOSED_STATUSES],
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
