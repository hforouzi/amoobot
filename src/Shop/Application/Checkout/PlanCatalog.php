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
     * @return list<Plan>
     */
    public function forSale(): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(Plan::class)->findBy(['isActive' => true], ['id' => 'ASC']),
            static fn (Plan $plan): bool => $plan->isAvailableForSale(),
        ));
    }

    public function findForSale(int $planId): ?Plan
    {
        $plan = $this->entityManager->getRepository(Plan::class)->find($planId);

        return $plan instanceof Plan && $plan->isAvailableForSale() ? $plan : null;
    }
}
