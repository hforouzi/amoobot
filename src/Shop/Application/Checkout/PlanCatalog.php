<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The plans a customer can buy (active, their panel/inbound open for sale, and the multi-inbound rule: PlanSaleRule),
 * for the bot, the Mini App and resellers.
 */
final class PlanCatalog
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanSaleRule $saleRule,
    ) {
    }

    /**
     * One query: the active plans with their inbound, its panel, their additional inbounds and those inbounds' panels
     * (the sale rule reads all of them; fetched here so it costs no query per plan).
     *
     * @return list<Plan>
     */
    public function forSale(): array
    {
        /** @var list<Plan> $plans */
        $plans = $this->entityManager->createQueryBuilder()
            ->select('p', 'i', 'pn', 'a', 'ai', 'apn')->from(Plan::class, 'p')
            ->leftJoin('p.inbound', 'i')->leftJoin('i.panel', 'pn')->leftJoin('p.additionalInbounds', 'a')
            ->leftJoin('a.inbound', 'ai')->leftJoin('ai.panel', 'apn')
            ->where('p.isActive = true')
            ->orderBy('p.id', 'ASC')
            ->getQuery()->getResult();

        return $this->saleRule->sellable($plans);
    }

    public function findForSale(int $planId): ?Plan
    {
        $plan = $this->entityManager->getRepository(Plan::class)->find($planId);

        return $plan instanceof Plan && $this->saleRule->isSellable($plan) ? $plan : null;
    }
}
