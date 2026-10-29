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
use App\Reseller\Domain\ClaimStatus;
use App\Reseller\Domain\ClaimToken;
use App\Reseller\Domain\ServiceClaimed;
use Doctrine\DBAL\LockMode;
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
 * claim(): one transaction (ClaimTransactions: READ COMMITTED, bounded retry of a deadlock or lock wait timeout, then
 * BUSY). Lock order: the service_claim row (by its id, the one the ticket names), then the order / payment rows of the
 * service under a shared lock (ServiceOrdersInFlight::existsLocked: it sees every settlement step committed before and
 * keeps them from moving on until the claim commits), then the vpn_service row. That order has no cycle with
 * settlement (order -> payment -> user -> wallet -> service). Checked under the locks: the link is open and not
 * expired, the service is still owned by the link's owner (fromUser) and not deleted, no order of the service is in
 * settlement, the claimer is not that owner, and a reseller's link still belongs to an active reseller. Written: the
 * service's owner, a new subscription page token (the old /s/... link stops working; the panel UUID, subId and config
 * links are kept, decision D4), the claim (claimed, by whom, when; open_service_id cleared) and one ownership event.
 * After the commit, best effort and never turning the claim into an error: ServiceClaimed is dispatched, the panel
 * client is told the new owner (ClaimPanelSync; retried by app:claims:expire), and the new and previous owner get a bot
 * message.
 *
 * A second delivery of the same confirmation (double tap, Telegram redelivery, a second process) finds the claim
 * already claimed by this same user, while the service is still his, and answers "done" again without writing
 * anything (after an admin's "Return to reseller" the old link is just unusable). Two claimers at once are serialized
 * by the claim row lock: the second sees it claimed and gets the generic answer.
 *
 * Rate limits (claim_attempt_user): previews and claims per Telegram user, invalid tokens included; the fifth invalid
 * token of one user within an hour is reported to the admins (claim_invalid_user, ClaimNotifier).
 */
class ClaimService
{
    public const OUTCOME_CLAIMED = 'claimed';
    public const OUTCOME_ALREADY_YOURS = 'already_yours';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResellerSettings $settings,
        private readonly ClaimTickets $tickets,
        private readonly ServiceOrdersInFlight $ordersInFlight,
        private readonly ClaimPanelSync $panelSync,
        private readonly ClaimNotifier $notifier,
        private readonly ClaimTransactions $transactions,
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
        if (!$claim->isOpen() && $claim->getClaimedByUser()?->getId() === $viewer->getId() && $claim->getService()->getUser()->getId() === $viewer->getId()) {
            // He opened his own used link again (or Telegram delivered /start twice after he confirmed): no oracle, it is his.
            // Not after it moved on (an admin returned it to the reseller): then it is just a link that cannot be used.
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

        try {
            [$outcome, $claim, $service, $claimer] = $this->transactions->run('claim', fn (): array => $this->claimOnce($claimId, (int) $claimer->getId(), $telegramUserId));
        } catch (ClaimException $e) {
            if (ClaimException::UNAVAILABLE === $e->error && 'expired' === $e->reason) {
                // Past its time but not swept yet: close it now (its own small transaction), so lists are honest.
                $this->expireNow($claimId);
            }
            if (ClaimException::UNAVAILABLE === $e->error) {
                $this->logger->info('claim_refused', ['claim_id' => $claimId, 'telegram_id' => $telegramUserId, 'reason' => $e->reason]);
            }

            throw $e;
        }

        if (self::OUTCOME_CLAIMED === $outcome) {
            $this->logger->info('claim_claimed', ['claim_id' => $claimId, 'service_id' => $service->getId(), 'to_user_id' => $claimer->getId()]);
            $this->afterCommit($claim, $service, $claimer, $telegramUserId);
        }

        return [$outcome, $service];
    }

    /**
     * The claim itself, inside ClaimTransactions (see the class comment for the lock order).
     *
     * @return array{string, ServiceClaim, VpnService, User}
     *
     * @throws ClaimException nothing was written
     */
    private function claimOnce(int $claimId, int $claimerId, string $telegramUserId): array
    {
        // 1. The claim row, by its id (a locking refresh: the row as committed now, not as this request first saw it).
        $claim = $this->entityManager->find(ServiceClaim::class, $claimId) ?? throw new ClaimException(ClaimException::UNAVAILABLE, 'gone');
        $this->entityManager->refresh($claim, LockMode::PESSIMISTIC_WRITE);
        // By id: a retried attempt may run on a fresh entity manager.
        $claimer = $this->entityManager->find(User::class, $claimerId) ?? throw new ClaimException(ClaimException::UNAVAILABLE, 'claimer gone');
        $now = $this->clock->now();
        $serviceId = (int) $claim->getService()->getId();

        if (!$claim->isOpen() && $claim->getClaimedByUser()?->getId() === $claimer->getId()) {
            // A second delivery of this user's own confirmation: done already, nothing to write, while it is still his.
            $owner = $this->entityManager->getConnection()->fetchOne('SELECT user_id FROM vpn_service WHERE id = ?', [$serviceId]);
            if (false !== $owner && (int) $owner === $claimer->getId()) {
                return [self::OUTCOME_ALREADY_YOURS, $claim, $claim->getService(), $claimer];
            }

            throw new ClaimException(ClaimException::UNAVAILABLE, 'no_longer_his');
        }
        if (null !== $claim->getReseller()) {
            $this->entityManager->refresh($claim->getReseller());
        }
        // The link itself here; the service (owner, status) only after its lock, below.
        $problem = $this->linkProblem($claim, $now, false);
        if (null !== $problem) {
            throw new ClaimException(ClaimException::UNAVAILABLE, $problem);
        }

        // 2. The service's orders and payments, under a shared lock (before the service row: no cycle with settlement).
        if ($this->ordersInFlight->existsLocked($serviceId)) {
            throw new ClaimException(ClaimException::BUSY, 'order_in_settlement');
        }

        // 3. The service row.
        $service = $this->entityManager->find(VpnService::class, $serviceId) ?? throw new ClaimException(ClaimException::UNAVAILABLE, 'service gone');
        $this->entityManager->refresh($service, LockMode::PESSIMISTIC_WRITE);
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

        $service->setUser($claimer)->rotatePublicToken()->setUpdatedAt($now);
        $claim->markClaimed($claimer, $telegramUserId, $now);
        $this->entityManager->persist(ServiceOwnershipEvent::claimed($claim, $from, $claimer, $now));

        return [self::OUTCOME_CLAIMED, $claim, $service, $claimer];
    }

    /** An open link found past its expiry at claim time: expired now, by its id (the sweep may get there first). */
    private function expireNow(int $claimId): void
    {
        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE service_claim SET status = ?, open_service_id = NULL, expired_at = ? WHERE id = ? AND status = ?',
                [ClaimStatus::EXPIRED, $this->clock->now()->format('Y-m-d H:i:s'), $claimId, ClaimStatus::OPEN],
            );
        } catch (\Throwable $e) {
            // Only tidying up: the sweep (app:claims:expire) closes it otherwise.
            $this->logger->warning('claim_expire_now_failed', ['claim_id' => $claimId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Best effort after the commit: never undoes the claim, and a failure here (listener, panel, Telegram, even the
     * failure handling itself) never reaches the claimer as an error: the claim is done.
     */
    private function afterCommit(ServiceClaim $claim, VpnService $service, User $claimer, string $telegramUserId): void
    {
        try {
            $this->events->dispatch(new ServiceClaimed((int) $claim->getId(), (int) $service->getId(), (int) $claim->getFromUser()->getId(), (int) $claimer->getId(), $claim->getReseller()?->getId()));
        } catch (\Throwable $e) {
            $this->logger->error('ServiceClaimed listener failed.', ['claim_id' => $claim->getId(), 'error' => $e->getMessage()]);
        }
        try {
            $this->panelSync->sync($claim);
        } catch (\Throwable $e) {
            // ClaimPanelSync never throws on a panel failure; this is its own bookkeeping failing. The claim stays
            // pending, so app:claims:expire tries again.
            $this->logger->error('claim_panel_sync_crashed', ['claim_id' => $claim->getId(), 'error' => $e->getMessage()]);
        }
        try {
            $this->notifier->claimed($claim, $service, $claimer, $telegramUserId);
        } catch (\Throwable $e) {
            $this->logger->error('claim_notification_crashed', ['claim_id' => $claim->getId(), 'error' => $e->getMessage()]);
        }
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
