<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The plans a customer can buy (active, and their panel/inbound open for sale), for the bot and the Mini App.
 */
final class PlanCatalog
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * One query: the active plans with their inbound, its panel and their additional inbounds (a plan with any is not
     * sold yet, Plan::isAvailableForSale(); fetched here so that check costs no query per plan).
     *
     * @return list<Plan>
     */
    public function forSale(): array
    {
        /** @var list<Plan> $plans */
        $plans = $this->entityManager->createQueryBuilder()
            ->select('p', 'i', 'pn', 'a')->from(Plan::class, 'p')
            ->leftJoin('p.inbound', 'i')->leftJoin('i.panel', 'pn')->leftJoin('p.additionalInbounds', 'a')
            ->where('p.isActive = true')
            ->orderBy('p.id', 'ASC')
            ->getQuery()->getResult();

        return array_values(array_filter($plans, static fn (Plan $plan): bool => $plan->isAvailableForSale()));
    }

    public function findForSale(int $planId): ?Plan
    {
        $plan = $this->entityManager->getRepository(Plan::class)->find($planId);

        return $plan instanceof Plan && $plan->isAvailableForSale() ? $plan : null;
    }
}
