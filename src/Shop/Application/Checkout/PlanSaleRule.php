<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\VpnInbound;
use App\Shop\Application\Catalog\TrialBackingPlans;
use App\Shop\Application\MultiInboundSettings;
use App\Shop\Domain\MultiInboundSale;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether a plan is sold, at every decision point (docs/design/multi-inbound-plans.md 4, D3; M2c F1). The rule itself
 * is MultiInboundSale::blocker(); this class feeds it plans.multi_inbound_enabled (read at most once per call, and only
 * when a plan has additional inbounds) and, for an order, the inbounds it was created with.
 *
 * - isSellable() / sellable(): listed and chosen (PlanCatalog: bot, Mini App, resellers);
 * - acceptsOrders(): a chosen plan becomes an order (draft confirmation, the bot's custom-draft payment);
 * - acceptsTrial(): a trial is claimed (TrialAccountService): its backing plan keeps one inbound (M2d B5);
 * - acceptsPayment(): an order is paid (PaymentStarter, WalletPayment): by its snapshot (Order::planInboundSnapshot()),
 *   or the live plan for an order without one. Renewals and add-traffic orders are not decided here: they only change
 *   the existing client (one email, all its inbounds), whatever the switch.
 *
 * A plan without additional inbounds is sold exactly as before.
 *
 * A trial's backing plan (TrialBackingPlans) is never sold, listed, ordered or paid as a new service, even when its row
 * is active and priced (activated by SQL, say): only acceptsTrial() takes it.
 */
final class PlanSaleRule
{
    public function __construct(
        private readonly MultiInboundSettings $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly TrialBackingPlans $trialBacking,
    ) {
    }

    public function isSellable(Plan $plan): bool
    {
        return $plan->isAvailableForSale($this->switch()) && !$this->trialBacking->isBacking($plan);
    }

    /**
     * @param list<Plan> $plans
     *
     * @return list<Plan>
     */
    public function sellable(array $plans): array
    {
        $switch = $this->switch();
        $plans = array_values(array_filter($plans, static fn (Plan $plan): bool => $plan->isAvailableForSale($switch)));
        // One query for the whole list.
        $backing = $this->trialBacking->backingAmong(array_values(array_filter(array_map(static fn (Plan $plan): ?int => $plan->getId(), $plans))));

        return [] === $backing ? $plans : array_values(array_filter($plans, static fn (Plan $plan): bool => !in_array($plan->getId(), $backing, true)));
    }

    /** A chosen plan may still become an order: active, and the multi-inbound rule holds. */
    public function acceptsOrders(Plan $plan): bool
    {
        return $plan->isActive() && null === $plan->multiInboundSaleBlocker($this->switch()) && !$this->trialBacking->isBacking($plan);
    }

    /**
     * A trial may be claimed on this backing plan (TrialPlan::getBackingPlan()): trial plans keep one inbound, so one with
     * additional inbounds (written before M2d, around the save rule) is never provisioned, whatever the switch.
     */
    public function acceptsTrial(Plan $backingPlan): bool
    {
        return !$backingPlan->hasAdditionalInbounds();
    }

    /**
     * An order may be paid now: for a new service, its plan is active and the multi-inbound rule holds for the inbounds
     * the order was created with (an inbound of the snapshot deleted since blocks it). Other order types: always.
     */
    public function acceptsPayment(Order $order): bool
    {
        if (OrderType::NEW_SERVICE !== $order->getType()) {
            return true;
        }
        $plan = $order->requirePlan();
        if (!$plan->isActive() || $this->trialBacking->isBacking($plan)) {
            return false;
        }
        $snapshot = $order->planInboundSnapshot();
        if (null === $snapshot) {
            return null === $plan->multiInboundSaleBlocker($this->switch());
        }
        if ([] === $snapshot['additional']) {
            return true;
        }
        $repository = $this->entityManager->getRepository(VpnInbound::class);
        $additional = [];
        foreach ($snapshot['additional'] as $id) {
            $additional[] = $repository->find($id) ?? null;
        }
        $primary = null === $snapshot['primary'] ? null : $repository->find($snapshot['primary']);
        if (in_array(null, $additional, true) || !$primary instanceof VpnInbound) {
            return false;
        }

        /** @var list<VpnInbound> $additional */
        return null === MultiInboundSale::blocker($primary, $additional, $this->switch());
    }

    /**
     * @return \Closure(): bool the switch, read on first use and remembered by this closure only
     */
    private function switch(): \Closure
    {
        $value = null;

        return function () use (&$value): bool {
            return $value ??= $this->settings->enabled();
        };
    }
}
