<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\TelegramAccount;
use App\Entity\TrialClaim;
use App\Entity\TrialPlan;
use App\Provisioning\Application\VpnProvisioningService;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

final class TrialAccountService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderTrackingCodeService $orderTrackingCodeService,
        private readonly VpnProvisioningService $vpnProvisioningService,
    ) {
    }

    public function claim(TelegramAccount $telegramAccount, TrialPlan $trialPlan): TrialClaimResult
    {
        if (!$trialPlan->isActive()) {
            return new TrialClaimResult(TrialClaimResult::STATUS_INACTIVE, 'در حال حاضر اکانت تست فعالی وجود ندارد.');
        }

        $validation = $this->validateClaimLimits($telegramAccount, $trialPlan);
        if ($validation instanceof TrialClaimResult) {
            return $validation;
        }

        $now = new \DateTimeImmutable();
        $backingPlan = $this->syncBackingPlan($trialPlan);
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

        try {
            $vpnService = $this->vpnProvisioningService->provisionOrder($order, [
                'source' => 'trial_claim',
                'trialPlanId' => $trialPlan->getId() ?? 0,
                'trialClaimId' => $claim->getId() ?? 0,
            ]);
        } catch (\Throwable $e) {
            $claim
                ->setStatus(TrialClaim::STATUS_FAILED)
                ->setFailureReason($e->getMessage());
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
