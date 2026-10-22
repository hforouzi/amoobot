<?php

declare(strict_types=1);

namespace App\Shop\Application\Checkout;

use App\Entity\TelegramAccount;
use App\Entity\TrialPlan;
use App\Shop\Application\TrialAccountService;
use App\Shop\Application\TrialClaimResult;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Trial accounts: the active trial plans in the admin's order, and claiming one (TrialAccountService decides
 * eligibility, limits and provisioning).
 */
final class TrialCheckout
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrialAccountService $trialAccountService,
    ) {
    }

    /**
     * @return list<TrialPlan>
     */
    public function activePlans(): array
    {
        /** @var list<TrialPlan> $trialPlans */
        $trialPlans = $this->entityManager->getRepository(TrialPlan::class)
            ->createQueryBuilder('plan')
            ->where('plan.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('plan.sortOrder', 'ASC')
            ->addOrderBy('plan.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $trialPlans;
    }

    public function activePlan(int $trialPlanId): ?TrialPlan
    {
        $trialPlan = $this->entityManager->getRepository(TrialPlan::class)->find($trialPlanId);

        return $trialPlan instanceof TrialPlan && $trialPlan->isActive() ? $trialPlan : null;
    }

    public function claim(TelegramAccount $account, TrialPlan $trialPlan): TrialClaimResult
    {
        return $this->trialAccountService->claim($account, $trialPlan);
    }
}
