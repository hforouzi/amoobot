<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Bot\Application\BotIdentity;
use App\Entity\Reseller;
use App\Entity\ServiceClaim;
use App\Entity\TrialClaim;
use App\Entity\User;
use App\Entity\VpnService;
use App\MiniApp\Application\MiniAppLinks;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Domain\ClaimException;
use App\Reseller\Domain\ClaimStatus;
use App\Reseller\Domain\ClaimToken;
use App\Reseller\Domain\CreatedClaimLink;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use App\Shared\Infrastructure\TransactionRollback;
use App\Shop\Domain\OrderType;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Claim links from the creator's side (docs/design/reseller-and-claim.md, 3.1 / 3.6): a reseller makes one for a
 * service he sold and still owns, an admin (SERVICES_MANAGE) for any live service; both can revoke. Shared by the bot,
 * the admin pages and (S3) the reseller Mini App: no logic in the callers.
 *
 * Creating a link: one transaction that takes the service's open link (if any, by open_service_id) and then the service
 * row FOR UPDATE, the same order as a claim (claim row, then service), re-checks that the service may be handed over,
 * revokes the open link (replaced) and inserts the new one with a fresh 256-bit token whose hash only is stored. The
 * unique open_service_id makes two open links for one service impossible even when two creations race; the loser is
 * retried (deadlock or duplicate key) and then replaces the winner's link. The token is returned once (CreatedClaimLink)
 * and never stored, logged or audited.
 *
 * A link cannot be made for: a deleted service, a trial, a service whose order is being paid / provisioned
 * (ServiceOrdersInFlight); for a reseller also not for a service he did not sell or no longer owns (claimed already).
 */
class ClaimLinks
{
    public const PER_PAGE = 8;
    private const ATTEMPTS = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResellerSettings $settings,
        private readonly ResellerCheckout $checkout,
        private readonly ServiceOrdersInFlight $ordersInFlight,
        private readonly TransactionRollback $transactionRollback,
        private readonly AdminAuditLogger $auditLogger,
        private readonly BotIdentity $botIdentity,
        private readonly MiniAppLinks $miniAppLinks,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.claim_create')]
        private readonly RateLimiterFactory $createLimiter,
    ) {
    }

    /**
     * Why this reseller may not make a link for this service now (ClaimException code), or null when he may. Read only:
     * the bot shows the "Make claim link" button only when null.
     */
    public function resellerProblem(User $user, VpnService $service): ?string
    {
        if (!$this->settings->claimsEnabled()) {
            return ClaimException::FEATURE_OFF;
        }
        $reseller = $this->checkout->resellerFor($user);
        if (!$reseller instanceof Reseller) {
            return ClaimException::RESELLER_UNAVAILABLE;
        }

        return $this->serviceProblem($service, $reseller);
    }

    /**
     * A new link for a service this reseller sold and still owns; his open link for it (if any) is revoked.
     *
     * @throws ClaimException claims_disabled, reseller_unavailable, not_claimable, rate_limited
     */
    public function createForReseller(User $user, int $serviceId): CreatedClaimLink
    {
        if (!$this->settings->claimsEnabled()) {
            throw new ClaimException(ClaimException::FEATURE_OFF);
        }
        $reseller = $this->checkout->resellerFor($user) ?? throw new ClaimException(ClaimException::RESELLER_UNAVAILABLE);
        $service = $serviceId > 0 ? $this->entityManager->find(VpnService::class, $serviceId) : null;
        if ($service instanceof VpnService) {
            // A long-running worker keeps its entities: read the row as it is now (the locked check below is the real one).
            $this->entityManager->refresh($service);
        }
        if (!$service instanceof VpnService || null !== ($problem = $this->serviceProblem($service, $reseller))) {
            throw new ClaimException(ClaimException::NOT_CLAIMABLE, $problem ?? 'unknown service');
        }
        if (!$this->createLimiter->create('reseller-'.$reseller->getId())->consume()->isAccepted()) {
            throw new ClaimException(ClaimException::RATE_LIMITED);
        }

        $resellerId = (int) $reseller->getId();
        $link = $this->create($service, function (VpnService $locked, string $tokenHash) use ($resellerId): ServiceClaim {
            // Read again by id: after a retried attempt the entity manager may be a new one.
            $reseller = $this->entityManager->find(Reseller::class, $resellerId) ?? throw new ClaimException(ClaimException::RESELLER_UNAVAILABLE);
            $this->entityManager->refresh($reseller);
            if (!$this->settings->claimsEnabled() || !$reseller->isActive()) {
                throw new ClaimException(ClaimException::RESELLER_UNAVAILABLE);
            }
            if (null !== ($problem = $this->serviceProblem($locked, $reseller))) {
                throw new ClaimException(ClaimException::NOT_CLAIMABLE, $problem);
            }

            return (new ServiceClaim($locked, $reseller->getUser(), $tokenHash, $this->clock->now(), $this->expiresAt()))->byReseller($reseller);
        }, $reseller->getUser()->getId(), null);
        $this->logger->info('claim_link_created', ['claim_id' => $link->claim->getId(), 'service_id' => $serviceId, 'reseller_id' => $reseller->getId(), 'replaced' => $link->replacedOpenLink]);

        return $link;
    }

    /**
     * A new link made by an admin (support case, SERVICES_MANAGE) for whoever owns the service now; an open link of the
     * service is revoked. Audited claim.create_admin (never the token).
     *
     * @throws ClaimException claims_disabled, not_claimable
     */
    public function createForAdmin(VpnService $service, AdminActor $admin): CreatedClaimLink
    {
        if (!$this->settings->claimsEnabled()) {
            throw new ClaimException(ClaimException::FEATURE_OFF);
        }
        if (null !== ($problem = $this->serviceProblem($service, null))) {
            throw new ClaimException(ClaimException::NOT_CLAIMABLE, $problem);
        }
        $link = $this->create($service, function (VpnService $locked, string $tokenHash) use ($admin): ServiceClaim {
            if (null !== ($problem = $this->serviceProblem($locked, null))) {
                throw new ClaimException(ClaimException::NOT_CLAIMABLE, $problem);
            }

            return (new ServiceClaim($locked, $locked->getUser(), $tokenHash, $this->clock->now(), $this->expiresAt()))->byAdmin($admin->adminUserId);
        }, null, $admin->adminUserId);
        $this->auditLogger->record('claim.create_admin', 'ServiceClaim', $link->claim->getId(), [
            'service_id' => $service->getId(),
            'from_user_id' => $link->claim->getFromUser()->getId(),
            'expires_at' => $link->claim->getExpiresAt()->format(\DATE_ATOM),
            'replaced_open_link' => $link->replacedOpenLink,
        ], $admin);

        return $link;
    }

    /**
     * Revokes one of this reseller's open links. Idempotent: false when it was not open (already used, expired or
     * revoked) or is not his.
     */
    public function revokeByReseller(User $user, int $claimId): bool
    {
        $reseller = $this->checkout->resellerFor($user);
        if (!$reseller instanceof Reseller) {
            return false;
        }

        return $this->revoke($claimId, static fn (ServiceClaim $claim): bool => $claim->getReseller()?->getId() === $reseller->getId(), ServiceClaim::REVOKED_BY_RESELLER, $user->getId(), null);
    }

    /**
     * Revokes any open link (admin, SERVICES_MANAGE). Idempotent; audited claim.revoke_admin when it changed something.
     */
    public function revokeByAdmin(int $claimId, AdminActor $admin): bool
    {
        $revoked = $this->revoke($claimId, static fn (): bool => true, ServiceClaim::REVOKED_BY_ADMIN, null, $admin->adminUserId);
        if ($revoked) {
            $this->auditLogger->record('claim.revoke_admin', 'ServiceClaim', $claimId, [], $admin);
        }

        return $revoked;
    }

    /**
     * Revokes every open link of this reseller, inside the caller's transaction (ResellerAdministration::disable, after
     * the reseller row lock). Claim rows only, never a service row: no lock cycle with a claim (claim, then service).
     *
     * @return int the links revoked
     */
    public function revokeAllOf(Reseller $reseller, ?int $adminUserId): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE service_claim SET status = ?, open_service_id = NULL, revoked_at = ?, revoke_reason = ?, revoked_by_admin_id = ? WHERE reseller_id = ? AND status = ?',
            [ClaimStatus::REVOKED, $this->clock->now()->format('Y-m-d H:i:s'), ServiceClaim::REVOKED_RESELLER_DISABLED, $adminUserId, $reseller->getId(), ClaimStatus::OPEN],
        );
    }

    /**
     * This reseller's links, open first, then newest first. What he may see: service, status, dates; never who claimed.
     *
     * @return array{items: list<ServiceClaim>, hasNext: bool}
     */
    public function linksOf(Reseller $reseller, int $page, int $perPage = self::PER_PAGE): array
    {
        $page = max(1, min(10000, $page));
        /** @var list<ServiceClaim> $rows */
        $rows = $this->entityManager->getRepository(ServiceClaim::class)->createQueryBuilder('c')
            ->join('c.service', 's')->addSelect('s')
            ->addSelect('CASE WHEN c.status = :open THEN 0 ELSE 1 END AS HIDDEN openFirst')
            ->where('c.reseller = :reseller')
            ->setParameter('reseller', $reseller)
            ->orderBy('openFirst', 'ASC')
            ->addOrderBy('c.id', 'DESC')
            ->setParameter('open', ClaimStatus::OPEN)
            ->setMaxResults($perPage + 1)
            ->setFirstResult(($page - 1) * $perPage)
            ->getQuery()->getResult();

        return ['items' => array_slice($rows, 0, $perPage), 'hasNext' => count($rows) > $perPage];
    }

    /**
     * When the service last moved away from this reseller's account by a claim ("claimed on"), or null. Read only.
     */
    public function claimedAwayAt(Reseller $reseller, VpnService $service): ?\DateTimeImmutable
    {
        $at = $this->entityManager->getConnection()->fetchOne(
            'SELECT MAX(claimed_at) FROM service_claim WHERE service_id = ? AND from_user_id = ? AND status = ?',
            [$service->getId(), $reseller->getUser()->getId(), ClaimStatus::CLAIMED],
        );

        return is_string($at) ? new \DateTimeImmutable($at) : null;
    }

    /** The open link of a service, if any (read only). */
    public function openLinkOf(VpnService $service): ?ServiceClaim
    {
        $claim = $this->entityManager->getRepository(ServiceClaim::class)->findOneBy(['openServiceId' => $service->getId()]);

        return $claim instanceof ServiceClaim ? $claim : null;
    }

    /**
     * Why this service may not be handed over now, or null. For a reseller also: he sold it and still owns it.
     */
    public function serviceProblem(VpnService $service, ?Reseller $reseller): ?string
    {
        $order = $service->getOrder();

        return match (true) {
            VpnServiceStatus::DELETED === $service->getStatus() => 'deleted',
            OrderType::TRIAL === $order?->getType() || $this->isTrial($service) => 'trial',
            null !== $reseller && $service->getSoldByReseller()?->getId() !== $reseller->getId() => 'not_sold_by_reseller',
            null !== $reseller && null === $order?->getReseller() => 'not_a_reseller_order',
            null !== $reseller && $service->getUser()->getId() !== $reseller->getUser()->getId() => 'not_owned_by_reseller',
            $this->ordersInFlight->exists((int) $service->getId()) => 'order_in_settlement',
            default => null,
        };
    }

    /**
     * @param \Closure(VpnService, string): ServiceClaim $build checks again under the locks and builds the new claim with
     *                                                    this token hash
     *
     * @throws ClaimException
     */
    private function create(VpnService $service, \Closure $build, ?int $byUserId, ?int $byAdminId): CreatedClaimLink
    {
        $serviceId = (int) $service->getId();
        for ($attempt = 1; ; ++$attempt) {
            $token = ClaimToken::generate();
            $connection = $this->entityManager->getConnection();
            $this->entityManager->flush();
            $level = $connection->getTransactionNestingLevel();
            $connection->beginTransaction();
            $claim = null;
            try {
                // Lock order as for a claim: the claim row (here: the service's open link, if any), then the service.
                $openId = $connection->fetchOne('SELECT id FROM service_claim WHERE open_service_id = ? FOR UPDATE', [$serviceId]);
                $connection->executeQuery('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
                $locked = $this->entityManager->find(VpnService::class, $serviceId) ?? throw new ClaimException(ClaimException::NOT_CLAIMABLE, 'service gone');
                $this->entityManager->refresh($locked);
                $claim = $build($locked, ClaimToken::hash($token));
                $replaced = false;
                if (false !== $openId) {
                    $old = $this->entityManager->find(ServiceClaim::class, (int) $openId);
                    if ($old instanceof ServiceClaim) {
                        $this->entityManager->refresh($old);
                        if ($old->isOpen()) {
                            $old->markRevoked(ServiceClaim::REVOKED_REPLACED, $byUserId, $byAdminId, $this->clock->now());
                            // Before the insert: Doctrine inserts before it updates, and the new row takes open_service_id.
                            $this->entityManager->flush();
                            $replaced = true;
                        }
                    }
                }
                $this->entityManager->persist($claim);
                $this->entityManager->flush();
                $connection->commit();

                return new CreatedClaimLink($claim, $token, $this->botLink($token), $this->appLink($token), $replaced);
            } catch (\Throwable $e) {
                if (!$this->transactionRollback->rollBack($level) || !$this->entityManager->isOpen()) {
                    $this->transactionRollback->resetEntityManager();
                } elseif ($claim instanceof ServiceClaim && $this->entityManager->contains($claim)) {
                    $this->entityManager->detach($claim);
                }
                // Two creations for one service at once: the loser runs again and then replaces the winner's link.
                if (($e instanceof DeadlockException || $e instanceof UniqueConstraintViolationException) && $attempt < self::ATTEMPTS && 0 === $level) {
                    usleep(random_int(10_000, 40_000) * $attempt);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * @param \Closure(ServiceClaim): bool $allowed
     */
    private function revoke(int $claimId, \Closure $allowed, string $reason, ?int $byUserId, ?int $byAdminId): bool
    {
        if ($claimId <= 0) {
            return false;
        }
        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $connection->beginTransaction();
        try {
            $connection->executeQuery('SELECT id FROM service_claim WHERE id = ? FOR UPDATE', [$claimId]);
            $claim = $this->entityManager->find(ServiceClaim::class, $claimId);
            if (!$claim instanceof ServiceClaim) {
                $connection->commit();

                return false;
            }
            $this->entityManager->refresh($claim);
            if (!$claim->isOpen() || !$allowed($claim)) {
                $connection->commit();

                return false;
            }
            $claim->markRevoked($reason, $byUserId, $byAdminId, $this->clock->now());
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
        $this->logger->info('claim_link_revoked', ['claim_id' => $claimId, 'reason' => $reason]);

        return true;
    }

    private function expiresAt(): \DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('+%d hours', $this->settings->claimTtlHours()));
    }

    private function isTrial(VpnService $service): bool
    {
        return null !== $service->getId() && null !== $this->entityManager->getRepository(TrialClaim::class)->findOneBy(['vpnService' => $service]);
    }

    private function botLink(string $token): ?string
    {
        $username = $this->botIdentity->username();

        return null === $username ? null : sprintf('https://t.me/%s?start=%s', $username, ClaimToken::payload($token));
    }

    /** The bot's main Mini App with the payload as its start parameter (needs a Main Mini App set in BotFather). */
    private function appLink(string $token): ?string
    {
        $username = $this->botIdentity->username();

        return null === $username || !$this->miniAppLinks->isEnabled() ? null : sprintf('https://t.me/%s?startapp=%s', $username, ClaimToken::payload($token));
    }
}
