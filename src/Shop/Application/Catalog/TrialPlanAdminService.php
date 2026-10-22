<?php

declare(strict_types=1);

namespace App\Shop\Application\Catalog;

use App\Entity\TrialPlan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * An admin's changes to trial plans, the same from the web panel (TrialPlanCrudController) and the Mini App: validate
 * (the entity's rules), save, activate / deactivate, delete. Callers check the permission (PLANS_MANAGE) and audit.
 */
final class TrialPlanAdminService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array<string, list<string>> field => messages; empty when the trial plan keeps the rules
     */
    public function violations(TrialPlan $trialPlan): array
    {
        return InvalidCatalogEntry::byField($this->validator->validate($trialPlan));
    }

    /**
     * @throws InvalidCatalogEntry nothing saved
     */
    public function save(TrialPlan $trialPlan): void
    {
        $violations = $this->violations($trialPlan);
        if ([] !== $violations) {
            throw new InvalidCatalogEntry($violations);
        }
        if (null === $trialPlan->getId()) {
            $this->entityManager->persist($trialPlan);
        } else {
            $trialPlan->setUpdatedAt(new \DateTimeImmutable());
        }
        $this->entityManager->flush();
    }

    /** @return bool false when the trial plan already was in that state (nothing written) */
    public function setActive(TrialPlan $trialPlan, bool $active): bool
    {
        if ($trialPlan->isActive() === $active) {
            return false;
        }
        $trialPlan->setIsActive($active)->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return true;
    }

    public function delete(TrialPlan $trialPlan): void
    {
        $this->entityManager->remove($trialPlan);
        $this->entityManager->flush();
    }
}
