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
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Reseller\Domain\ClaimException;
use App\Reseller\Domain\ClaimStatus;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use App\Shared\Infrastructure\TransactionRollback;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * What can happen to a service after a claim link moved it (docs/design/reseller-and-claim.md, 3.4 D4 / 3.6):
 *
 * - returnToReseller(): the wrong person claimed it. An admin (SERVICES_MANAGE) moves it back to the reseller who sold
 *   it, with a reason: a new subscription page token, an ownership event (actor admin), an audit row (claim.revert),
 *   the panel told the owner again, both sides told. There is no self-service undo. Lock order as for a claim: claim
 *   rows (the last claim of the service, its open link if any), then the service row.
 * - resetConnectionLinks(): the new owner's "Reset my connection links": the panel client gets a new UUID and subId
 *   (CredentialRotationDriverInterface), then the links are rebuilt through the regeneration path
 *   (ServiceConfigRegenerator). Every installed app must import the new links; the reseller's copies stop working.
 *   Only for the owner who got the service by a claim, while no order of it is in settlement, on a panel that can do it.
 */
class ServiceOwnership
{
    public const REASON_MIN = 3;
    public const REASON_MAX = 255;
    /** Protocols whose clients authenticate by UUID (the only ones a rotation can renew). */
    private const UUID_PROTOCOLS = ['vless', 'vmess'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ResellerSettings $settings,
        private readonly ServiceOrdersInFlight $ordersInFlight,
        private readonly ClaimPanelSync $panelSync,
        private readonly ClaimNotifier $notifier,
        private readonly AdminAuditLogger $auditLogger,
        private readonly TransactionRollback $transactionRollback,
        private readonly CustomerServiceFinder $customerServices,
        private readonly VpnPanelDriverRegistry $drivers,
        private readonly ServiceConfigRegenerator $regenerator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
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
        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            // Claim rows first (the one that moved it, an open link if any), then the service row.
            $lastId = $connection->fetchOne('SELECT id FROM service_claim WHERE service_id = ? AND status = ? ORDER BY claimed_at DESC, id DESC LIMIT 1 FOR UPDATE', [$serviceId, ClaimStatus::CLAIMED]);
            $openId = $connection->fetchOne('SELECT id FROM service_claim WHERE open_service_id = ? FOR UPDATE', [$serviceId]);
            $connection->executeQuery('SELECT id FROM vpn_service WHERE id = ? FOR UPDATE', [$serviceId]);
            $locked = $this->entityManager->find(VpnService::class, $serviceId) ?? throw new ClaimException(ClaimException::NOT_RETURNABLE, 'service gone');
            $this->entityManager->refresh($locked);
            $problem = $this->returnProblem($locked);
            if (null !== $problem || false === $lastId) {
                throw new ClaimException(ClaimException::NOT_RETURNABLE, $problem ?? 'never_claimed');
            }
            if ($this->ordersInFlight->exists($serviceId)) {
                throw new ClaimException(ClaimException::BUSY, 'order_in_settlement');
            }
            $now = $this->clock->now();
            if (false !== $openId) {
                $open = $this->entityManager->find(ServiceClaim::class, (int) $openId);
                if ($open instanceof ServiceClaim && $open->isOpen()) {
                    $open->markRevoked(ServiceClaim::REVOKED_RETURNED, null, $admin->adminUserId, $now);
                }
            }
            $from = $locked->getUser();
            $to = $locked->getSoldByReseller()?->getUser() ?? throw new ClaimException(ClaimException::NOT_RETURNABLE, 'not_sold_by_reseller');
            $locked->setUser($to)->rotatePublicToken()->setUpdatedAt($now);
            $this->entityManager->persist(ServiceOwnershipEvent::returnedByAdmin($locked, $from, $to, $admin->adminUserId, $reason, $now));
            $last = $this->entityManager->find(ServiceClaim::class, (int) $lastId) ?? throw new ClaimException(ClaimException::NOT_RETURNABLE, 'claim gone');
            $this->entityManager->refresh($last);
            $last->requestPanelSync();
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if (!$this->transactionRollback->rollBack($level) || !$this->entityManager->isOpen()) {
                $this->transactionRollback->resetEntityManager();
            } else {
                $this->entityManager->clear();
            }

            throw $e;
        }
        // The audit row carries ids and the reason only (never a link or the customer's Telegram data).
        $this->auditLogger->record('claim.revert', 'VpnService', $serviceId, ['from_user_id' => $from->getId(), 'to_user_id' => $to->getId(), 'claim_id' => (int) $lastId, 'reason' => $reason], $admin);
        $this->logger->info('claim_returned_to_reseller', ['service_id' => $serviceId, 'claim_id' => (int) $lastId]);
        $this->panelSync->sync($last);
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
     * New connection credentials for a service its owner got by a claim link. Nothing is changed when it is refused.
     *
     * @throws ClaimException claims_disabled, not_found, reset_unsupported, service_busy, reset_failed
     */
    public function resetConnectionLinks(User $owner, int $serviceId): VpnService
    {
        $service = $this->customerServices->ownedBy($owner, $serviceId) ?? throw new ClaimException(ClaimException::NOT_FOUND);
        $problem = $this->resetProblem($owner, $service);
        if (null !== $problem) {
            throw new ClaimException(match ($problem) {
                'claims_disabled' => ClaimException::FEATURE_OFF,
                'busy' => ClaimException::BUSY,
                'not_claimed_by_owner' => ClaimException::NOT_FOUND,
                default => ClaimException::RESET_UNSUPPORTED,
            }, $problem);
        }
        $driver = $this->drivers->resolve($service->getPanel());
        \assert($driver instanceof CredentialRotationDriverInterface);
        try {
            $rotated = $driver->rotateCredentials((string) $service->getRemoteId(), $service->getPanel());
        } catch (\Throwable $e) {
            $this->logger->warning('claim_reset_links_failed', ['service_id' => $serviceId, 'error' => $e->getMessage()]);

            throw new ClaimException(ClaimException::RESET_FAILED, $e->getMessage());
        }
        $this->entityManager->refresh($service);
        $service
            ->setRemoteId($rotated->remoteId)
            ->setClientUuid($rotated->clientUuid)
            ->setSubId($rotated->subId)
            ->setSubscriptionUrl($rotated->subscriptionUrl ?? $service->getSubscriptionUrl())
            ->setConfigLinks([] === $rotated->configLinks ? null : $rotated->configLinks)
            ->setConfigText([] === $rotated->configLinks ? null : implode("\n", $rotated->configLinks))
            ->setUpdatedAt($this->clock->now());
        $this->entityManager->flush();
        // The regeneration path rebuilds the stored links from the new identity (a failure keeps the panel's links).
        $result = $this->regenerator->regenerate($service, 'claim_reset_links');
        if (!$result->succeeded()) {
            $this->logger->warning('claim_reset_links_regenerate_failed', ['service_id' => $serviceId, 'outcome' => $result->outcome]);
        }
        if ($this->ordersInFlight->exists($serviceId)) {
            // An order started while the panel was being updated: its own panel write may restore the old row.
            $this->logger->warning('claim_reset_links_raced_an_order', ['service_id' => $serviceId]);
        }
        $this->logger->info('claim_reset_links', ['service_id' => $serviceId, 'user_id' => $owner->getId()]);

        return $service;
    }

    private function resetProblem(User $owner, VpnService $service): ?string
    {
        // Cheapest first: with claim links off every service view stops at the setting.
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
