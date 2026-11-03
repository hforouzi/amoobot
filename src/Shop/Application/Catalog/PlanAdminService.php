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
 *
 * A trial's backing plan (TrialBackingPlans) is not changed here at all: every write refuses it (TrialBackingPlanLocked,
 * nothing written). Its trial plan manages it (TrialPlanAdminService, TrialAccountService).
 */
final class PlanAdminService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly TrialBackingPlans $trialBacking,
    ) {
    }

    /**
     * @throws TrialBackingPlanLocked
     */
    public function assertSalesPlan(Plan $plan): void
    {
        if ($this->trialBacking->isBacking($plan)) {
            throw new TrialBackingPlanLocked((int) $plan->getId());
        }
    }

    /**
     * @return array<string, list<string>> field => messages; empty when the plan keeps the rules
     */
    public function violations(Plan $plan): array
    {
        return InvalidCatalogEntry::byField($this->validator->validate($plan));
    }

    /**
     * @throws InvalidCatalogEntry    nothing saved
     * @throws TrialBackingPlanLocked nothing saved
     */
    public function save(Plan $plan): void
    {
        $this->assertSalesPlan($plan);
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

    /**
     * @return bool false when the plan already was in that state (nothing written)
     *
     * @throws TrialBackingPlanLocked
     */
    public function setActive(Plan $plan, bool $active): bool
    {
        $this->assertSalesPlan($plan);
        if ($plan->isActive() === $active) {
            return false;
        }
        $plan->setIsActive($active)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }

    /**
     * @return int the price before
     *
     * @throws TrialBackingPlanLocked
     */
    public function changePrice(Plan $plan, int $price): int
    {
        $this->assertSalesPlan($plan);
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
     * @throws PlanInUse              orders refer to it; nothing deleted
     * @throws TrialBackingPlanLocked nothing deleted (its trial plan deletes it, TrialPlanAdminService)
     */
    public function delete(Plan $plan): void
    {
        $this->assertSalesPlan($plan);
        $orders = $this->orderCount($plan);
        if ($orders > 0) {
            throw new PlanInUse($orders);
        }
        $this->entityManager->remove($plan);
        $this->entityManager->flush();
    }
}
