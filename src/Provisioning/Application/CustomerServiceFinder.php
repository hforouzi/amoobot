<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\User;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The services a customer sees as theirs (the bot's "My services" and the Mini App): active, suspended or expired,
 * never deleted, newest first. ownedBy() is the ownership check for a service id coming from the customer.
 */
final class CustomerServiceFinder
{
    public const VISIBLE_STATUSES = [VpnServiceStatus::ACTIVE, VpnServiceStatus::SUSPENDED, VpnServiceStatus::EXPIRED];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<VpnService>
     */
    public function visibleTo(User $user, ?int $limit = null): array
    {
        /** @var list<VpnService> $services */
        $services = $this->entityManager->getRepository(VpnService::class)
            ->createQueryBuilder('service')
            ->andWhere('service.user = :user')
            ->andWhere('service.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', self::VISIBLE_STATUSES)
            ->orderBy('service.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $services;
    }

    /** The service if it exists, belongs to this customer and is not deleted; null for anything else (same answer). */
    public function ownedBy(User $user, int $serviceId): ?VpnService
    {
        $service = $this->entityManager->getRepository(VpnService::class)->find($serviceId);
        if (!$service instanceof VpnService || $service->getUser()->getId() !== $user->getId() || !in_array($service->getStatus(), self::VISIBLE_STATUSES, true)) {
            return null;
        }

        return $service;
    }
}
