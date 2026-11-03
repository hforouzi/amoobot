<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Shop\Application\Checkout\PlanSaleRule;
use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\TelegramAccount;
use App\Entity\TrialClaim;
use App\Entity\TrialPlan;
use App\Provisioning\Application\VpnProvisioningService;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Claiming a trial: the limits, the backing plan (syncBackingPlan(), docs/design/trial-backing-plans.md), a trial order
 * and claim, then provisioning.
 *
 * Lock order of the trial area: the trial_plan row (FOR UPDATE), then its backing plan row, then the new order and
 * claim. The checks, the backing plan and the order are one transaction under the trial_plan lock, so claims of one
 * trial plan are serialized (its limits hold) and a delete of the trial plan (TrialPlanAdminService::delete(), same
 * order) either comes first (the claim finds no trial plan: nothing is created) or sees this claim's order (the
 * backing plan is kept). Provisioning (a panel call) runs after the commit, outside the lock: a trial plan deleted
 * meanwhile takes its claim row along (ON DELETE CASCADE, as before) and the customer still gets the service.
 */
final class TrialAccountService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderTrackingCodeService $orderTrackingCodeService,
        private readonly VpnProvisioningService $vpnProvisioningService,
        private readonly PlanSaleRule $saleRule,
    ) {
    }

    public function claim(TelegramAccount $telegramAccount, TrialPlan $trialPlan): TrialClaimResult
    {
        if (!$trialPlan->isActive()) {
            return new TrialClaimResult(TrialClaimResult::STATUS_INACTIVE, 'در حال حاضر اکانت تست فعالی وجود ندارد.');
        }

        $created = $this->entityManager->wrapInTransaction(fn (): TrialClaimResult|array => $this->createClaim($telegramAccount, $trialPlan));
        if ($created instanceof TrialClaimResult) {
            return $created;
        }
        [$order, $claim] = $created;

        try {
            $vpnService = $this->vpnProvisioningService->provisionOrder($order, [
                'source' => 'trial_claim',
                'trialPlanId' => $trialPlan->getId() ?? 0,
                'trialClaimId' => $claim->getId() ?? 0,
            ]);
        } catch (\Throwable $e) {
            $claim
                ->setStatus(TrialClaim::STATUS_FAILED)
                ->setFailureReason(SecretRedactor::redactAccess($e->getMessage(), 1000));
            $order->setStatus(OrderStatus::FAILED);
            $this->entityManager->flush();

            return new TrialClaimResult(
                TrialClaimResult::STATUS_FAILED,
                'ساخت اکانت تست با خطا مواجه شد. لطفاً بعداً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
                $claim,
                $order
            );
        }

        $claim
            ->setStatus(TrialClaim::STATUS_PROVISIONED)
            ->setVpnService($vpnService)
            ->setProvisionedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return new TrialClaimResult(
            TrialClaimResult::STATUS_SUCCESS,
            'اکانت تست شما آماده شد.',
            $claim,
            $order,
            $vpnService
        );
    }

    /**
     * Under the trial_plan row's lock (see the class comment): the trial plan as it is now, its limits, the backing
     * plan, the order and the pending claim, flushed. A refusal is returned as the result, with nothing written.
     *
     * @return TrialClaimResult|array{Order, TrialClaim}
     */
    private function createClaim(TelegramAccount $telegramAccount, TrialPlan $trialPlan): TrialClaimResult|array
    {
        if (false === $this->entityManager->getConnection()->fetchOne('SELECT id FROM trial_plan WHERE id = ? FOR UPDATE', [$trialPlan->getId()])) {
            // Deleted meanwhile.
            return new TrialClaimResult(TrialClaimResult::STATUS_INACTIVE, 'در حال حاضر اکانت تست فعالی وجود ندارد.');
        }
        // Deactivated or changed meanwhile: read again under the lock.
        $this->entityManager->refresh($trialPlan);
        if (!$trialPlan->isActive()) {
            return new TrialClaimResult(TrialClaimResult::STATUS_INACTIVE, 'در حال حاضر اکانت تست فعالی وجود ندارد.');
        }

        $validation = $this->validateClaimLimits($telegramAccount, $trialPlan);
        if ($validation instanceof TrialClaimResult) {
            return $validation;
        }

        $now = new \DateTimeImmutable();
        $backingPlan = $this->syncBackingPlan($trialPlan);
        if (!$this->saleRule->acceptsTrial($backingPlan)) {
            // Trial plans keep one inbound (M2d B5): never provisioned with additional ones. Nothing is written: the
            // synced fields are taken back (a later flush must not store them).
            if (null === $backingPlan->getId()) {
                $trialPlan->setBackingPlan(null);
            } else {
                $this->entityManager->refresh($backingPlan);
            }

            return new TrialClaimResult(TrialClaimResult::STATUS_INACTIVE, 'در حال حاضر اکانت تست فعالی وجود ندارد.');
        }
        $order = (new Order())
            ->setUser($telegramAccount->getUser())
            ->setPlan($backingPlan)
            ->setAmount(0)
            ->setType(OrderType::TRIAL)
            ->setStatus(OrderStatus::PAID)
            ->setPaidAt($now)
            ->setMetadata([
                'trial' => true,
                'trialPlanId' => $trialPlan->getId(),
                'trialPlanTitle' => $trialPlan->getTitle(),
                'trafficGb' => $trialPlan->getTrafficGb(),
                'durationDays' => $trialPlan->getDurationDays(),
                'unlimitedDuration' => false,
            ]);
        $this->orderTrackingCodeService->assignIfMissing($order);

        $claim = (new TrialClaim())
            ->setTelegramAccount($telegramAccount)
            ->setTrialPlan($trialPlan)
            ->setOrder($order)
            ->setStatus(TrialClaim::STATUS_PENDING);

        $this->entityManager->persist($backingPlan);
        $this->entityManager->persist($order);
        $this->entityManager->persist($claim);
        $this->entityManager->flush();

        return [$order, $claim];
    }

    private function validateClaimLimits(TelegramAccount $telegramAccount, TrialPlan $trialPlan): ?TrialClaimResult
    {
        $countedStatuses = [TrialClaim::STATUS_PENDING, TrialClaim::STATUS_PROVISIONED];
        $userClaimCount = (int) $this->entityManager->getRepository(TrialClaim::class)
            ->createQueryBuilder('claim')
            ->select('COUNT(claim.id)')
            ->where('claim.telegramAccount = :telegramAccount')
            ->andWhere('claim.trialPlan = :trialPlan')
            ->andWhere('claim.status IN (:statuses)')
            ->setParameter('telegramAccount', $telegramAccount)
            ->setParameter('trialPlan', $trialPlan)
            ->setParameter('statuses', $countedStatuses)
            ->getQuery()
            ->getSingleScalarResult();

        if ($userClaimCount >= $trialPlan->getMaxClaimsPerUser()) {
            return new TrialClaimResult(TrialClaimResult::STATUS_ALREADY_CLAIMED, 'شما قبلاً اکانت تست دریافت کرده‌اید.');
        }

        $maxClaimsTotal = $trialPlan->getMaxClaimsTotal();
        if (null !== $maxClaimsTotal) {
            $totalClaimCount = (int) $this->entityManager->getRepository(TrialClaim::class)
                ->createQueryBuilder('claim')
                ->select('COUNT(claim.id)')
                ->where('claim.trialPlan = :trialPlan')
                ->andWhere('claim.status IN (:statuses)')
                ->setParameter('trialPlan', $trialPlan)
                ->setParameter('statuses', $countedStatuses)
                ->getQuery()
                ->getSingleScalarResult();

            if ($totalClaimCount >= $maxClaimsTotal) {
                return new TrialClaimResult(TrialClaimResult::STATUS_LIMIT_REACHED, 'در حال حاضر اکانت تست فعالی وجود ندارد.');
            }
        }

        $cooldownHours = $trialPlan->getCooldownHours();
        if (null !== $cooldownHours && $cooldownHours > 0) {
            $since = (new \DateTimeImmutable())->modify(sprintf('-%d hours', $cooldownHours));
            $recentClaim = $this->entityManager->getRepository(TrialClaim::class)
                ->createQueryBuilder('claim')
                ->where('claim.telegramAccount = :telegramAccount')
                ->andWhere('claim.trialPlan = :trialPlan')
                ->andWhere('claim.createdAt >= :since')
                ->setParameter('telegramAccount', $telegramAccount)
                ->setParameter('trialPlan', $trialPlan)
                ->setParameter('since', $since)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($recentClaim instanceof TrialClaim) {
                return new TrialClaimResult(TrialClaimResult::STATUS_COOLDOWN, 'شما قبلاً اکانت تست دریافت کرده‌اید.');
            }
        }

        return null;
    }

    private function syncBackingPlan(TrialPlan $trialPlan): Plan
    {
        $plan = $trialPlan->getBackingPlan();
        if (!$plan instanceof Plan) {
            $plan = new Plan();
            $trialPlan->setBackingPlan($plan);
        }

        $plan
            ->setTitle('[Trial] '.$trialPlan->getTitle())
            ->setDescription($trialPlan->getDescription())
            ->setDurationDays($trialPlan->getDurationDays())
            ->setTrafficGb($trialPlan->getTrafficGb())
            ->setIpLimit($trialPlan->getIpLimit())
            ->setPrice(0)
            ->setIsActive(false)
            ->setIsCustomizable(false)
            ->setAllowCustomUsername(false)
            ->setIsUnlimitedDuration(false)
            ->setInbound($trialPlan->getInbound())
            ->setUpdatedAt(new \DateTimeImmutable());

        return $plan;
    }
}
