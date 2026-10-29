<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\ServiceClaim;
use App\Entity\ServiceOwnershipEvent;
use App\Entity\User;
use App\Entity\VpnService;
use App\Provisioning\Application\CustomerServiceFinder;
use App\Provisioning\Application\ServiceConfigRegenerator;
use App\Provisioning\Domain\CredentialRotationDriverInterface;
use App\Provisioning\Domain\Dto\RotatedCredentials;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Reseller\Domain\ClaimException;
use App\Reseller\Domain\ClaimStatus;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use App\Shared\Infrastructure\TransactionRollback;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * What can happen to a service after a claim link moved it (docs/design/reseller-and-claim.md, 3.4 D4 / 3.6):
 *
 * - returnToReseller(): the wrong person claimed it. An admin (SERVICES_MANAGE) moves it back to the reseller who sold
 *   it, with a reason: a new subscription page token, an ownership event (actor admin), an audit row (claim.revert),
 *   the panel told the owner again, both sides told. There is no self-service undo. One ClaimTransactions transaction:
 *   the claim rows (the last claim of the service, its open link if any) found by a plain read and locked by id in
 *   ascending order, then the service's orders under a shared lock, then the service row; checked again under them.
 * - resetConnectionLinks(): the new owner's "Reset my connection links": the panel client gets a new UUID and subId
 *   (CredentialRotationDriverInterface), then the links are rebuilt through the regeneration path
 *   (ServiceConfigRegenerator). Every installed app must import the new links; the reseller's copies stop working.
 *   Only for the owner who got the service by a claim, while no order of it is in settlement, on a panel that can do it.
 *
 * The reset, step by step:
 *
 * 1. The vpn_service row is locked (FOR UPDATE) for the whole reset, panel call included, and nothing else (no
 *    wallet, order or payment row): a claim, a return to the reseller or a second reset of the service waits until it
 *    is done, then sees the new state. Owner, claim and state are checked again under the lock (READ COMMITTED, so
 *    the check reads what is committed now) and once more right before the database write.
 * 2. The confirm button carries resetNonce(): a digest of the credentials it was shown for. A second delivery of the
 *    same button (double tap, Telegram redelivery) finds other credentials and answers "already done": one rotation.
 * 3. A few resets per service per day (rate limiter claim_reset_links), counted only when a rotation is attempted.
 * 4. The new UUID / subId are chosen here. If the panel call fails, the panel is asked what it has now: the new
 *    credentials (it applied them; the answer was lost) are written to the database as a success; the old ones mean
 *    nothing changed (the user is told so); anything else, or no answer, is "unconfirmed": an admin is alerted and
 *    the user is told it could not be confirmed. If the database write fails after the panel changed, it is written
 *    again (RESET_WRITE_ATTEMPTS); if that fails too, the same alert and answer.
 * 5. After the commit the panel is read once more: a renewal, suspend, expiry or owner update that read the client
 *    before the rotation and wrote it back after would have put the old credentials back (every panel write is a
 *    full-row replacement merged from a fresh read, so only a read-before / write-after overlap of a few milliseconds
 *    can do that). If so the new credentials are applied again; if they still do not hold, an admin is alerted.
 *    Those writers merge from the row they read just before writing and log sanaei_modern_client_uuid_differs when
 *    their own copy of the UUID is older than the panel's.
 */
class ServiceOwnership
{
    public const REASON_MIN = 3;
    public const REASON_MAX = 255;
    public const RESET_DONE = 'reset';
    public const RESET_ALREADY_DONE = 'already_reset';
    /** Protocols whose clients authenticate by UUID (the only ones a rotation can renew). */
    private const UUID_PROTOCOLS = ['vless', 'vmess'];
    private const RESET_WRITE_ATTEMPTS = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResellerSettings $settings,
        private readonly ServiceOrdersInFlight $ordersInFlight,
        private readonly ClaimPanelSync $panelSync,
        private readonly ClaimNotifier $notifier,
        private readonly AdminAuditLogger $auditLogger,
        private readonly ClaimTransactions $transactions,
        private readonly TransactionRollback $transactionRollback,
        private readonly CustomerServiceFinder $customerServices,
        private readonly VpnPanelDriverRegistry $drivers,
        private readonly ServiceConfigRegenerator $regenerator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.claim_reset_links')]
        private readonly RateLimiterFactory $resetLimiter,
    ) {
    }

    /** Why "Return to reseller" is not possible for this service now, or null. */
    public function returnProblem(VpnService $service): ?string
    {
        $seller = $service->getSoldByReseller()?->getUser();

        return match (true) {
            null === $seller => 'not_sold_by_reseller',
            VpnServiceStatus::DELETED === $service->getStatus() => 'deleted',
            $service->getUser()->getId() === $seller->getId() => 'already_with_reseller',
            null === $this->lastClaim($service) => 'never_claimed',
            default => null,
        };
    }

    /**
     * @throws ClaimException reason_required, not_returnable, service_busy
     */
    public function returnToReseller(VpnService $service, string $reason, AdminActor $admin): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::REASON_MIN || mb_strlen($reason) > self::REASON_MAX) {
            throw new ClaimException(ClaimException::REASON_REQUIRED);
        }
        $serviceId = (int) $service->getId();

        /** @var array{User, User, ServiceClaim, VpnService} $result */
        $result = $this->transactions->run('return_to_reseller', function () use ($serviceId, $reason, $admin): array {
            $connection = $this->entityManager->getConnection();
            // 1. Claim rows: found by a plain read, then locked by id (ascending) and checked again.
            $lastId = $connection->fetchOne('SELECT id FROM service_claim WHERE service_id = ? AND status = ? ORDER BY claimed_at DESC, id DESC LIMIT 1', [$serviceId, ClaimStatus::CLAIMED]);
            if (false === $lastId) {
                throw new ClaimException(ClaimException::NOT_RETURNABLE, 'never_claimed');
            }
            $openId = $connection->fetchOne('SELECT id FROM service_claim WHERE open_service_id = ?', [$serviceId]);
            $claims = $this->lockClaims(array_values(array_filter([(int) $lastId, false === $openId ? null : (int) $openId])));
            $last = $claims[(int) $lastId] ?? throw new ClaimException(ClaimException::NOT_RETURNABLE, 'claim gone');
            // 2. The service's orders, shared (before the service row, as every ownership change).
            if ($this->ordersInFlight->existsLocked($serviceId)) {
                throw new ClaimException(ClaimException::BUSY, 'order_in_settlement');
            }
            // 3. The service row.
            $locked = $this->entityManager->find(VpnService::class, $serviceId) ?? throw new ClaimException(ClaimException::NOT_RETURNABLE, 'service gone');
            $this->entityManager->refresh($locked, LockMode::PESSIMISTIC_WRITE);
            $problem = $this->returnProblem($locked);
            if (null !== $problem) {
                throw new ClaimException(ClaimException::NOT_RETURNABLE, $problem);
            }
            // Changed between the plain read and the locks (another claim, a new link): start again, in lock order.
            $openNow = $connection->fetchOne('SELECT id FROM service_claim WHERE open_service_id = ?', [$serviceId]);
            if ($locked->getUser()->getId() !== $last->getClaimedByUser()?->getId() || (false !== $openNow && !isset($claims[(int) $openNow]))) {
                throw new ClaimRowsChanged('The claim rows of the service changed while they were being locked.');
            }

            $now = $this->clock->now();
            foreach ($claims as $claim) {
                if ($claim->isOpen() && $claim->getService()->getId() === $serviceId) {
                    $claim->markRevoked(ServiceClaim::REVOKED_RETURNED, null, $admin->adminUserId, $now);
                }
            }
            $from = $locked->getUser();
            $to = $locked->getSoldByReseller()?->getUser() ?? throw new ClaimException(ClaimException::NOT_RETURNABLE, 'not_sold_by_reseller');
            $locked->setUser($to)->rotatePublicToken()->setUpdatedAt($now);
            $this->entityManager->persist(ServiceOwnershipEvent::returnedByAdmin($locked, $from, $to, $admin->adminUserId, $reason, $now));
            $last->requestPanelSync();

            return [$from, $to, $last, $locked];
        });
        [$from, $to, $last, $locked] = $result;

        // The audit row carries ids and the reason only (never a link or the customer's Telegram data).
        $this->auditLogger->record('claim.revert', 'VpnService', $serviceId, ['from_user_id' => $from->getId(), 'to_user_id' => $to->getId(), 'claim_id' => (int) $last->getId(), 'reason' => $reason], $admin);
        $this->logger->info('claim_returned_to_reseller', ['service_id' => $serviceId, 'claim_id' => (int) $last->getId()]);
        try {
            $this->panelSync->sync($last);
        } catch (\Throwable $e) {
            $this->logger->error('claim_panel_sync_crashed', ['claim_id' => $last->getId(), 'error' => $e->getMessage()]);
        }
        $this->notifier->returned($locked, $from, $to);
    }

    /**
     * Whether this owner may reset the connection links of this service (the bot shows the button only then).
     */
    public function canResetLinks(User $owner, VpnService $service): bool
    {
        return null === $this->resetProblem($owner, $service);
    }

    /**
     * What the confirm button of a reset carries: a digest of the credentials the warning was shown for (not a secret:
     * only the owner can reset). Once they changed, the same button is a second delivery and rotates nothing.
     */
    public function resetNonce(VpnService $service): string
    {
        return substr(hash('sha256', implode('|', ['reset-links-v1', (string) $service->getId(), (string) $service->getClientUuid(), (string) $service->getSubId()])), 0, 12);
    }

    /**
     * New connection credentials for a service its owner got by a claim link (see the class comment for every step).
     *
     * @param string $nonce resetNonce() of the service as the confirm button showed it
     *
     * @return array{string, VpnService} RESET_DONE, or RESET_ALREADY_DONE (a second delivery: nothing rotated)
     *
     * @throws ClaimException claims_disabled, not_found, reset_unsupported, service_busy, rate_limited, reset_failed (nothing
     *                        changed), reset_unconfirmed (the panel may have changed; an admin was alerted)
     */
    public function resetConnectionLinks(User $owner, int $serviceId, string $nonce): array
    {
        $service = $this->customerServices->ownedBy($owner, $serviceId) ?? throw new ClaimException(ClaimException::NOT_FOUND);
        $this->assertResettable($owner, $service);

        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $level = $connection->getTransactionNestingLevel();
        if (0 === $level) {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $connection->beginTransaction();
        $rotated = null;
        try {
            // 1. The service row, and only it, for the whole reset.
            $service = $this->entityManager->find(VpnService::class, $serviceId) ?? throw new ClaimException(ClaimException::NOT_FOUND);
            $this->entityManager->refresh($service, LockMode::PESSIMISTIC_WRITE);
            $this->assertResettable($owner, $service);
            // 2. A second delivery of the same confirmation: the credentials it was for are gone already.
            if (!hash_equals($this->resetNonce($service), $nonce)) {
                $connection->commit();
                $this->logger->info('claim_reset_links_already_done', ['service_id' => $serviceId]);

                return [self::RESET_ALREADY_DONE, $service];
            }
            // 3. The daily limit (claim_reset_links).
            if (!$this->resetLimiter->create('service-'.$serviceId)->consume()->isAccepted()) {
                throw new ClaimException(ClaimException::RATE_LIMITED, 'reset');
            }
            // 4. The panel.
            $driver = $this->drivers->resolve($service->getPanel());
            \assert($driver instanceof CredentialRotationDriverInterface);
            $oldUuid = (string) $service->getClientUuid();
            $newUuid = Uuid::v4()->toRfc4122();
            $newSubId = bin2hex(random_bytes(8));
            try {
                $rotated = $driver->rotateCredentials((string) $service->getRemoteId(), $newUuid, $newSubId, $service->getPanel());
            } catch (\Throwable $e) {
                $this->logger->warning('claim_reset_links_panel_error', ['service_id' => $serviceId, 'error' => $e->getMessage()]);
                $rotated = $this->whatThePanelHas($driver, $service, $newUuid, $oldUuid);
                if (null === $rotated) {
                    throw new ClaimException(ClaimException::RESET_FAILED, 'the panel kept the old credentials: '.$e->getMessage());
                }
                $this->logger->warning('claim_reset_links_applied_despite_error', ['service_id' => $serviceId]);
            }
            // Before writing: the row as it is now (still locked). The panel changed, so the database must say so.
            $this->entityManager->refresh($service);
            if ($service->getUser()->getId() !== $owner->getId()) {
                $this->logger->warning('claim_reset_links_owner_changed_meanwhile', ['service_id' => $serviceId]);
            }
            $this->applyCredentials($service, $rotated);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $this->rollBack($level);
            if (null === $rotated) {
                if ($e instanceof ClaimException) {
                    if (ClaimException::RESET_UNCONFIRMED === $e->error) {
                        $this->notifier->resetNeedsAttention($serviceId, $e->reason);
                    }

                    throw $e;
                }
                if ($e instanceof RetryableException) {
                    // The service row stayed locked (a claim, a return or another reset of it): nothing was changed.
                    throw new ClaimException(ClaimException::BUSY, 'reset: '.$e::class);
                }

                throw new ClaimException(ClaimException::RESET_FAILED, $e->getMessage());
            }
            // The panel has the new credentials; the database write failed: write it again.
            $this->logger->error('claim_reset_links_db_write_failed', ['service_id' => $serviceId, 'error' => $e->getMessage()]);
            $service = $this->writeCredentialsAgain($serviceId, $rotated);
            if (null === $service) {
                $this->notifier->resetNeedsAttention($serviceId, 'the panel has new credentials but the database could not be updated');

                throw new ClaimException(ClaimException::RESET_UNCONFIRMED, 'db write failed after the panel changed');
            }
        }

        // The regeneration path rebuilds the stored links from the new identity (a failure keeps the panel's links).
        try {
            $result = $this->regenerator->regenerate($service, 'claim_reset_links');
            if (!$result->succeeded()) {
                $this->logger->warning('claim_reset_links_regenerate_failed', ['service_id' => $serviceId, 'outcome' => $result->outcome]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('claim_reset_links_regenerate_failed', ['service_id' => $serviceId, 'error' => $e->getMessage()]);
        }
        // 5. A concurrent full-row write may have put the old credentials back: check, and apply them again.
        $this->verifyRotationHeld($service, $rotated);
        $this->logger->info('claim_reset_links', ['service_id' => $serviceId, 'user_id' => $owner->getId()]);

        return [self::RESET_DONE, $service];
    }

    /**
     * @throws ClaimException claims_disabled, not_found, service_busy, reset_unsupported
     */
    private function assertResettable(User $owner, VpnService $service): void
    {
        $problem = $this->resetProblem($owner, $service);
        if (null !== $problem) {
            throw new ClaimException(match ($problem) {
                'claims_disabled' => ClaimException::FEATURE_OFF,
                'busy' => ClaimException::BUSY,
                'not_claimed_by_owner' => ClaimException::NOT_FOUND,
                default => ClaimException::RESET_UNSUPPORTED,
            }, $problem);
        }
    }

    /**
     * After a failed panel call: the new credentials (applied, the answer was lost), null (the old ones: nothing
     * changed), or reset_unconfirmed (no answer, or something else).
     *
     * @throws ClaimException reset_unconfirmed
     */
    private function whatThePanelHas(CredentialRotationDriverInterface $driver, VpnService $service, string $newUuid, string $oldUuid): ?RotatedCredentials
    {
        try {
            $now = $driver->currentCredentials((string) $service->getRemoteId(), $service->getPanel());
        } catch (\Throwable $e) {
            throw new ClaimException(ClaimException::RESET_UNCONFIRMED, 'the panel could not be read after a failed reset: '.$e->getMessage());
        }
        if (null === $now) {
            throw new ClaimException(ClaimException::RESET_UNCONFIRMED, 'the panel no longer has the client');
        }
        if (0 === strcasecmp($now->clientUuid, $newUuid)) {
            return $now;
        }
        if ('' !== $oldUuid && 0 === strcasecmp($now->clientUuid, $oldUuid)) {
            return null;
        }

        throw new ClaimException(ClaimException::RESET_UNCONFIRMED, 'the panel shows credentials that are neither the old nor the new ones');
    }

    private function applyCredentials(VpnService $service, RotatedCredentials $rotated): void
    {
        $service
            ->setRemoteId($rotated->remoteId)
            ->setClientUuid($rotated->clientUuid)
            ->setSubId($rotated->subId)
            ->setSubscriptionUrl($rotated->subscriptionUrl ?? $service->getSubscriptionUrl())
            ->setConfigLinks([] === $rotated->configLinks ? null : $rotated->configLinks)
            ->setConfigText([] === $rotated->configLinks ? null : implode("\n", $rotated->configLinks))
            ->setUpdatedAt($this->clock->now());
    }

    /** The database write after the panel already changed, again: the service as saved, or null when it kept failing. */
    private function writeCredentialsAgain(int $serviceId, RotatedCredentials $rotated): ?VpnService
    {
        for ($attempt = 1; $attempt <= self::RESET_WRITE_ATTEMPTS; ++$attempt) {
            try {
                $service = $this->entityManager->find(VpnService::class, $serviceId);
                if (!$service instanceof VpnService) {
                    return null;
                }
                $this->entityManager->refresh($service);
                $this->applyCredentials($service, $rotated);
                $this->entityManager->flush();
                $this->logger->warning('claim_reset_links_db_repaired', ['service_id' => $serviceId, 'attempt' => $attempt]);

                return $service;
            } catch (\Throwable $e) {
                $this->logger->error('claim_reset_links_db_repair_failed', ['service_id' => $serviceId, 'attempt' => $attempt, 'error' => $e->getMessage()]);
                $this->transactionRollback->resetEntityManager();
                usleep(100_000 * $attempt);
            }
        }

        return null;
    }

    /** After the commit: the panel still has the new credentials, or they are applied again; else an admin is told. */
    private function verifyRotationHeld(VpnService $service, RotatedCredentials $rotated): void
    {
        $driver = null === $service->getPanel() ? null : $this->drivers->resolve($service->getPanel());
        if (!$driver instanceof CredentialRotationDriverInterface) {
            return;
        }
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $now = $driver->currentCredentials((string) $service->getRemoteId(), $service->getPanel());
                if (null !== $now && 0 === strcasecmp($now->clientUuid, $rotated->clientUuid) && $now->subId === $rotated->subId) {
                    return;
                }
                $this->logger->warning('claim_reset_links_reverted_on_panel', ['service_id' => $service->getId(), 'attempt' => $attempt]);
                $driver->rotateCredentials((string) $service->getRemoteId(), $rotated->clientUuid, $rotated->subId, $service->getPanel());
            } catch (\Throwable $e) {
                $this->logger->warning('claim_reset_links_verify_failed', ['service_id' => $service->getId(), 'attempt' => $attempt, 'error' => $e->getMessage()]);
            }
        }
        try {
            $now = $driver->currentCredentials((string) $service->getRemoteId(), $service->getPanel());
            if (null !== $now && 0 === strcasecmp($now->clientUuid, $rotated->clientUuid) && $now->subId === $rotated->subId) {
                return;
            }
        } catch (\Throwable) {
        }
        $this->notifier->resetNeedsAttention((int) $service->getId(), 'after the reset the panel does not show the new credentials');
    }

    private function rollBack(int $level): void
    {
        if ($this->entityManager->getConnection()->getTransactionNestingLevel() > $level) {
            if (!$this->transactionRollback->rollBack($level) || !$this->entityManager->isOpen()) {
                $this->transactionRollback->resetEntityManager();

                return;
            }
        }
        $this->entityManager->clear();
    }

    /**
     * Locks these claim rows by primary key, in ascending order.
     *
     * @param list<int> $ids
     *
     * @return array<int, ServiceClaim> by id
     */
    private function lockClaims(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);
        $claims = [];
        foreach ($ids as $id) {
            $claim = $this->entityManager->find(ServiceClaim::class, $id);
            if ($claim instanceof ServiceClaim) {
                $this->entityManager->refresh($claim, LockMode::PESSIMISTIC_WRITE);
                $claims[$id] = $claim;
            }
        }

        return $claims;
    }

    private function resetProblem(User $owner, VpnService $service): ?string
    {
        // Cheapest first: with claim links off every service view stops at the setting (remembered per request).
        if (!$this->settings->claimsEnabled()) {
            return 'claims_disabled';
        }
        if ($service->getUser()->getId() !== $owner->getId() || VpnServiceStatus::DELETED === $service->getStatus() || !$this->claimedBy($owner, $service)) {
            return 'not_claimed_by_owner';
        }
        $driver = null === $service->getPanel() ? null : $this->drivers->resolve($service->getPanel());
        $protocol = strtolower((string) $service->getInbound()?->getProtocol());

        return match (true) {
            !$driver instanceof CredentialRotationDriverInterface || !$driver->canRotateCredentials((string) $service->getRemoteId(), $service->getPanel()) => 'driver',
            '' !== $protocol && !in_array($protocol, self::UUID_PROTOCOLS, true) => 'protocol',
            $this->ordersInFlight->exists((int) $service->getId()) => 'busy',
            default => null,
        };
    }

    /** The owner's current ownership came from a claim link (the latest ownership event of the service). */
    private function claimedBy(User $owner, VpnService $service): bool
    {
        $last = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT to_user_id, claim_id FROM service_ownership_event WHERE service_id = ? ORDER BY id DESC LIMIT 1',
            [$service->getId()],
        );

        return false !== $last && (int) $last['to_user_id'] === $owner->getId() && null !== $last['claim_id'];
    }

    private function lastClaim(VpnService $service): ?ServiceClaim
    {
        $claim = $this->entityManager->getRepository(ServiceClaim::class)->findOneBy(['service' => $service, 'status' => ClaimStatus::CLAIMED], ['claimedAt' => 'DESC', 'id' => 'DESC']);

        return $claim instanceof ServiceClaim ? $claim : null;
    }
}
