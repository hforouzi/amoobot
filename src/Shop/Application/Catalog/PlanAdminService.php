<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\Order;
use App\Entity\Plan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * An admin's changes to plans, the same from the web panel (PlanCrudController), the bot (AdminPlansHandler) and the
 * Mini App: validate (the entity's rules), save (create or update), activate / deactivate, change the price, delete
 * (refused while orders refer to the plan). Callers check the permission (PLANS_MANAGE) and audit.
 */
final class PlanAdminService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array<string, list<string>> field => messages; empty when the plan keeps the rules
     */
    public function violations(Plan $plan): array
    {
        return InvalidCatalogEntry::byField($this->validator->validate($plan));
    }

    /**
     * @throws InvalidCatalogEntry nothing saved
     */
    public function save(Plan $plan): void
    {
        $violations = $this->violations($plan);
        if ([] !== $violations) {
            throw new InvalidCatalogEntry($violations);
        }
        if (null === $plan->getId()) {
            $this->entityManager->persist($plan);
        } else {
            $plan->setUpdatedAt(new \DateTimeImmutable());
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

    /** How many orders refer to the plan (it cannot be deleted while there are any). */
    public function orderCount(Plan $plan): int
    {
        return null === $plan->getId() ? 0 : $this->entityManager->getRepository(Order::class)->count(['plan' => $plan]);
    }

    /**
     * @throws PlanInUse orders refer to it; nothing deleted
     */
    public function delete(Plan $plan): void
    {
        $orders = $this->orderCount($plan);
        if ($orders > 0) {
            throw new PlanInUse($orders);
        }
        $this->entityManager->remove($plan);
        $this->entityManager->flush();
    }
}
