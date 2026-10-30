<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\VpnInbound;
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
 * - acceptsPayment(): an order is paid (PaymentStarter, WalletPayment): by its snapshot (Order::planInboundSnapshot()),
 *   or the live plan for an order without one. Renewals and add-traffic orders are not decided here: they only change
 *   the existing client (one email, all its inbounds), whatever the switch.
 *
 * A plan without additional inbounds is sold exactly as before.
 */
final class PlanSaleRule
{
    public function __construct(
        private readonly MultiInboundSettings $settings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function isSellable(Plan $plan): bool
    {
        return $plan->isAvailableForSale($this->switch());
    }

    /**
     * @param list<Plan> $plans
     *
     * @return list<Plan>
     */
    public function sellable(array $plans): array
    {
        $switch = $this->switch();

        return array_values(array_filter($plans, static fn (Plan $plan): bool => $plan->isAvailableForSale($switch)));
    }

    /** A chosen plan may still become an order: active, and the multi-inbound rule holds. */
    public function acceptsOrders(Plan $plan): bool
    {
        return $plan->isActive() && null === $plan->multiInboundSaleBlocker($this->switch());
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
        if (!$plan->isActive()) {
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
