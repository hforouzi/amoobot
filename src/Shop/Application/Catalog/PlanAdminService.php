<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An admin's changes to plans, the same from the web panel (PlanCrudController), the bot (AdminPlansHandler) and the
 * Mini App: save (create or update), activate / deactivate, change the price, delete. Callers check the permission
 * (PLANS_MANAGE) and audit.
 */
final class PlanAdminService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function save(Plan $plan): void
    {
        if (null === $plan->getId()) {
            $this->entityManager->persist($plan);
        }
        $this->entityManager->flush();
    }

    /** @return bool false when the plan already was in that state (nothing written) */
    public function setActive(Plan $plan, bool $active): bool
    {
        if ($plan->isActive() === $active) {
            return false;
        }
        $plan->setIsActive($active)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }

    /** @return int the price before */
    public function changePrice(Plan $plan, int $price): int
    {
        $before = $plan->getPrice();
        $plan->setPrice($price)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $before;
    }

    public function delete(Plan $plan): void
    {
        $this->entityManager->remove($plan);
        $this->entityManager->flush();
    }
}
