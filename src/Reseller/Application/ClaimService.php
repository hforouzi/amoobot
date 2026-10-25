<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Reseller;
use App\Entity\ServiceClaim;
use App\Entity\ServiceOwnershipEvent;
use App\Entity\User;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Domain\ClaimException;
use App\Reseller\Domain\ClaimPreview;
use App\Reseller\Domain\ClaimToken;
use App\Reseller\Domain\ServiceClaimed;
use App\Shared\Infrastructure\TransactionRollback;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Using a claim link (docs/design/reseller-and-claim.md, 3.3 / 3.4), for the bot and the Mini App alike.
 *
 * preview(): the token (from /start claim_... or the Mini App start parameter) is checked for its exact shape, then
 * looked up by its SHA-256 (indexed, unique). Nothing changes. The answer is the service's plan, traffic left and expiry,
 * the reseller's display name, and a ticket (ClaimTickets) bound to this claim and this Telegram user: opening a link
 * never claims (link-preview bots and prefetches cannot), the confirm button does, and it carries the ticket, never the
 * token. Every "not usable" case answers the same ClaimException::UNAVAILABLE (no oracle); the real reason is logged.
 *
 * claim(): one transaction. Lock order: the service_claim row (FOR UPDATE, by the id the ticket names), then the
 * vpn_service row. No wallet, order or payment row is locked or written, so it cannot deadlock with settlement (order
 * -> payment -> user -> wallet). Checked under the locks: the link is open and not expired, the service is still owned
 * by the link's owner (fromUser) and not deleted, no order of the service is in settlement (ServiceOrdersInFlight), the
 * claimer is not that owner, and a reseller's link still belongs to an active reseller with claims on. Written: the
 * service's owner, a new subscription page token (the old /s/... link stops working; the panel UUID, subId and config
 * links are kept, decision D4), the claim (claimed, by whom, when; open_service_id cleared) and one ownership event.
 * After the commit, best effort: ServiceClaimed is dispatched, the panel client is told the new owner (ClaimPanelSync;
 * retried by app:claims:expire), and the new and previous owner get a bot message.
 *
 * A second delivery of the same confirmation (double tap, Telegram redelivery, a second process) finds the claim
 * already claimed by this same user and answers "done" again without writing anything. Two claimers at once are
 * serialized by the claim row lock: the second sees it claimed and gets the generic answer.
 *
 * Rate limits (claim_attempt_user): previews and claims per Telegram user, invalid tokens included; the fifth invalid
 * token of one user within an hour is reported to the admins (claim_invalid_user, ClaimNotifier).
 */
class ClaimService
{
    public const OUTCOME_CLAIMED = 'claimed';
    public const OUTCOME_ALREADY_YOURS = 'already_yours';
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResellerSettings $settings,
        private readonly ClaimTickets $tickets,
        private readonly ServiceOrdersInFlight $ordersInFlight,
        private readonly ClaimPanelSync $panelSync,
        private readonly ClaimNotifier $notifier,
        private readonly TransactionRollback $transactionRollback,
        private readonly EventDispatcherInterface $events,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.claim_attempt_user')]
        private readonly RateLimiterFactory $attemptLimiter,
        #[Autowire(service: 'limiter.claim_invalid_user')]
        private readonly RateLimiterFactory $invalidLimiter,
    ) {
    }

    /**
     * What this link would hand over, for the confirmation step. Nothing is written.
     *
     * @param string $token the part after "claim_", exactly as received (nothing trimmed)
     *
     * @throws ClaimException claim_unavailable, rate_limited, own_service
     */
    public function preview(#[\SensitiveParameter] string $token, User $viewer, string $telegramUserId): ClaimPreview
    {
        $this->assertEnabled($telegramUserId);
        $this->consumeAttempt($telegramUserId);
        if (!ClaimToken::isWellFormed($token)) {
            $this->invalid($telegramUserId, null, 'malformed');
        }
        $claim = $this->entityManager->getRepository(ServiceClaim::class)->findOneBy(['tokenHash' => ClaimToken::hash($token)]);
        if (!$claim instanceof ServiceClaim) {
            $this->invalid($telegramUserId, null, 'unknown');
        }
        $this->entityManager->refresh($claim);

        return $this->previewOf($claim, $viewer, $telegramUserId, null);
    }

    /**
     * @param string|null $ticket the ticket already issued (Mini App screen), or null to issue one
     *
     * @throws ClaimException claim_unavailable, own_service, already_yours
     */
    private function previewOf(ServiceClaim $claim, User $viewer, string $telegramUserId, ?string $ticket): ClaimPreview
    {
        // The service and reseller as they are now (a long-running worker keeps its entities); the claim re-checks under
        // its locks.
        $this->entityManager->refresh($claim->getService());
        if (null !== $claim->getReseller()) {
            $this->entityManager->refresh($claim->getReseller());
        }
        if (!$claim->isOpen() && $claim->getClaimedByUser()?->getId() === $viewer->getId()) {
            // He opened his own used link again (or Telegram delivered /start twice after he confirmed): no oracle, it is his.
            throw new ClaimException(ClaimException::ALREADY_YOURS);
        }
        $problem = $this->linkProblem($claim, $this->clock->now());
        if (null !== $problem) {
            $this->refuse($telegramUserId, $claim, $problem);
        }
        $service = $claim->getService();
        if ($claim->getFromUser()->getId() === $viewer->getId()) {
            throw new ClaimException(ClaimException::OWN_SERVICE);
        }

        return new ClaimPreview(
            (int) $claim->getId(),
            $ticket ?? $this->tickets->issue((int) $claim->getId(), $telegramUserId, $claim->getTokenHash()),
            (int) $service->getId(),
            (string) ($service->getOrder()?->getPlan()?->getTitle() ?? '-'),
            $service->getTrafficLimitBytes(),
            $service->getTrafficUsedBytes(),
            $service->getExpiresAt(),
            $claim->getReseller()?->getDisplayName(),
            $claim->getExpiresAt(),
        );
    }

    /**
     * The preview again from a ticket (the Mini App's claim screen: the token itself never travels after the start
     * parameter). Nothing is written.
     *
     * @throws ClaimException claim_unavailable, own_service, already_yours
     */
    public function previewForTicket(int $claimId, string $ticket, User $viewer, string $telegramUserId): ClaimPreview
    {
        $this->assertEnabled($telegramUserId);
        $claim = $claimId > 0 ? $this->entityManager->find(ServiceClaim::class, $claimId) : null;
        if (!$claim instanceof ServiceClaim || !$this->tickets->isValid($ticket, $claimId, $telegramUserId, $claim->getTokenHash())) {
            $this->invalid($telegramUserId, $claimId, 'bad_ticket');
        }
        $this->entityManager->refresh($claim);

        return $this->previewOf($claim, $viewer, $telegramUserId, $ticket);
    }

    /**
     * Hands the service over to this user (the confirm button of the preview).
     *
     * @return array{string, VpnService} OUTCOME_* and the service (now his)
     *
     * @throws ClaimException claim_unavailable, rate_limited, service_busy, own_service
     */
    public function claim(int $claimId, string $ticket, User $claimer, string $telegramUserId): array
    {
        $this->assertEnabled($telegramUserId);
        $this->consumeAttempt($telegramUserId);
        $tokenHash = $claimId > 0 ? $this->entityManager->getConnection()->fetchOne('SELECT token_hash FROM service_claim WHERE id = ?', [$claimId]) : false;
        if (!is_string($tokenHash) || !$this->tickets->isValid($ticket, $claimId, $telegramUserId, $tokenHash)) {
            $this->invalid($telegramUserId, $claimId, 'bad_ticket');
        }

        for ($attempt = 1; ; ++$attempt) {
            try {
                [$outcome, $claim, $service, $claimer] = $this->claimOnce($claimId, (int) $claimer->getId(), $telegramUserId);
                break;
            } catch (DeadlockException $e) {
                $this->logger->warning('claim_deadlock_victim', ['claim_id' => $claimId, 'attempt' => $attempt]);
                if ($attempt >= self::DEADLOCK_ATTEMPTS) {
                    throw new ClaimException(ClaimException::BUSY, 'deadlock');
                }
                usleep(random_int(20_000, 60_000) * $attempt);
            }
        }

        if (self::OUTCOME_CLAIMED === $outcome) {
            $this->logger->info('claim_claimed', ['claim_id' => $claimId, 'service_id' => $service->getId(), 'to_user_id' => $claimer->getId()]);
            $this->afterCommit($claim, $service, $claimer, $telegramUserId);
        }

        return [$outcome, $service];
    }

    /**
     * @return array{string, ServiceClaim, VpnService, User}
     *
     * @throws ClaimException|DeadlockException nothing was written
     */
    private function claimOnce(int $claimId, int $claimerId, string $telegramUserId): array
    {
        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            // 1. The claim row, 2. the service row: the one lock order of every claim-link write.
            $connection->executeQuery('SELECT id FROM service_claim WHERE id = ? FOR UPDATE', [$claimId]);
            $claim = $this->entityManager->find(ServiceClaim::class, $claimId) ?? throw new ClaimException(ClaimException::UNAVAILABLE, 'gone');
            $this->entityManager->refresh($claim);
            // By id: a retried attempt may run on a fresh entity manager.
            $claimer = $this->entityManager->find(User::class, $claimerId) ?? throw new ClaimException(ClaimException::UNAVAILABLE, 'claimer gone');
            $now = $this->clock->now();

            if (!$claim->isOpen() && $claim->getClaimedByUser()?->getId() === $claimer->getId()) {
                // A second delivery of this user's own confirmation: already done, nothing to write.
                $connection->commit();

                return [self::OUTCOME_ALREADY_YOURS, $claim, $claim->getService(), $claimer];
            }
            if ($claim->isOpen() && $claim->isExpiredAt($now)) {
                // Past its time but not swept yet: close it now, so lists are honest.
                $claim->markExpired($now);
                $this->entityManager->flush();
                $connection->commit();

                throw new ClaimException(ClaimException::UNAVAILABLE, 'expired');
            }
            if (null !== $claim->getReseller()) {
                $this->entityManager->refresh($claim->getReseller());
            }
            // The link itself here; the service (owner, status) only after its lock, below.
            $problem = $this->linkProblem($claim, $now, false);
            if (null !== $problem) {
                throw new ClaimException(ClaimException::UNAVAILABLE, $problem);
            }

            $serviceId = (int) $claim->getService()->getId();
            $connection->executeQuery('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $service = $this->entityManager->find(VpnService::class, $serviceId) ?? throw new ClaimException(ClaimException::UNAVAILABLE, 'service gone');
            $this->entityManager->refresh($service);
            $from = $claim->getFromUser();
            if ($service->getUser()->getId() !== $from->getId()) {
                throw new ClaimException(ClaimException::UNAVAILABLE, 'owner_changed');
            }
            if (VpnServiceStatus::DELETED === $service->getStatus()) {
                throw new ClaimException(ClaimException::UNAVAILABLE, 'service_deleted');
            }
            if ($claimer->getId() === $from->getId()) {
                throw new ClaimException(ClaimException::OWN_SERVICE);
            }
            if ($this->ordersInFlight->exists($serviceId)) {
                throw new ClaimException(ClaimException::BUSY, 'order_in_settlement');
            }

            $service->setUser($claimer)->rotatePublicToken()->setUpdatedAt($now);
            $claim->markClaimed($claimer, $telegramUserId, $now);
            $this->entityManager->persist(ServiceOwnershipEvent::claimed($claim, $from, $claimer, $now));
            $this->entityManager->flush();
            $connection->commit();

            return [self::OUTCOME_CLAIMED, $claim, $service, $claimer];
        } catch (\Throwable $e) {
            if ($connection->getTransactionNestingLevel() > $level) {
                if (!$this->transactionRollback->rollBack($level) || !$this->entityManager->isOpen()) {
                    $this->transactionRollback->resetEntityManager();
                } else {
                    // The unit of work may hold the half-made change: start clean.
                    $this->entityManager->clear();
                }
            }
            if ($e instanceof ClaimException && ClaimException::UNAVAILABLE === $e->error) {
                $this->logger->info('claim_refused', ['claim_id' => $claimId, 'telegram_id' => $telegramUserId, 'reason' => $e->reason]);
            }

            throw $e;
        }
    }

    /** Best effort after the commit: never undoes the claim. */
    private function afterCommit(ServiceClaim $claim, VpnService $service, User $claimer, string $telegramUserId): void
    {
        try {
            $this->events->dispatch(new ServiceClaimed((int) $claim->getId(), (int) $service->getId(), (int) $claim->getFromUser()->getId(), (int) $claimer->getId(), $claim->getReseller()?->getId()));
        } catch (\Throwable $e) {
            $this->logger->error('ServiceClaimed listener failed.', ['claim_id' => $claim->getId(), 'error' => $e->getMessage()]);
        }
        $this->panelSync->sync($claim);
        $this->notifier->claimed($claim, $service, $claimer, $telegramUserId);
    }

    /**
     * Why this link cannot be used now, or null. The reason is for logs and the creator's list only.
     *
     * @param bool $withService also the service's owner and status (the preview; the claim checks them under the lock)
     */
    private function linkProblem(ServiceClaim $claim, \DateTimeImmutable $now, bool $withService = true): ?string
    {
        $reseller = $claim->getReseller();

        return match (true) {
            !$claim->isOpen() => $claim->getStatus(),
            $claim->isExpiredAt($now) => 'expired',
            $reseller instanceof Reseller && !$reseller->isActive() => 'reseller_disabled',
            $withService && $claim->getService()->getUser()->getId() !== $claim->getFromUser()->getId() => 'owner_changed',
            $withService && VpnServiceStatus::DELETED === $claim->getService()->getStatus() => 'service_deleted',
            default => null,
        };
    }

    /**
     * @throws ClaimException claim_unavailable (claims switched off: links simply do not work)
     */
    private function assertEnabled(string $telegramUserId): void
    {
        if (!$this->settings->claimsEnabled()) {
            $this->logger->info('claim_refused', ['telegram_id' => $telegramUserId, 'reason' => 'claims_disabled']);

            throw new ClaimException(ClaimException::UNAVAILABLE, 'claims_disabled');
        }
    }

    /**
     * @throws ClaimException rate_limited
     */
    private function consumeAttempt(string $telegramUserId): void
    {
        if (!$this->attemptLimiter->create($telegramUserId)->consume()->isAccepted()) {
            $this->logger->info('claim_rate_limited', ['telegram_id' => $telegramUserId]);

            throw new ClaimException(ClaimException::RATE_LIMITED);
        }
    }

    /**
     * An invalid token or ticket: counted (the fifth in an hour is reported to the admins), then the generic answer.
     *
     * @throws ClaimException claim_unavailable, always
     */
    private function invalid(string $telegramUserId, ?int $claimId, string $reason): never
    {
        $limit = $this->invalidLimiter->create($telegramUserId)->consume();
        if ($limit->isAccepted() && 0 === $limit->getRemainingTokens()) {
            $this->notifier->invalidAttempts($telegramUserId, $limit->getLimit());
        }
        $this->logger->info('claim_refused', ['claim_id' => $claimId, 'telegram_id' => $telegramUserId, 'reason' => $reason]);

        throw new ClaimException(ClaimException::UNAVAILABLE, $reason);
    }

    /**
     * @throws ClaimException claim_unavailable, always
     */
    private function refuse(string $telegramUserId, ServiceClaim $claim, string $reason): never
    {
        $this->logger->info('claim_refused', ['claim_id' => $claim->getId(), 'telegram_id' => $telegramUserId, 'reason' => $reason]);

        throw new ClaimException(ClaimException::UNAVAILABLE, $reason);
    }
}
