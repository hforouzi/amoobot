<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Reseller;
use App\Entity\ResellerCustomer;
use App\Entity\ResellerCustomerService;
use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The services a reseller sold, as his Mini App lists them (docs/design/reseller-and-claim.md, 6.2 / S3), read only.
 * A service is his to list while vpn_service.sold_by_reseller_id is his and it is not deleted, also after a customer
 * claimed it (D5). Whether it is still his own is vpn_service.user_id = his user: "claimed" otherwise. Nothing here
 * reads the claimer: the list shows plan, status, expiry, traffic and his own label, never the owner.
 *
 * One query for a page (with its plan), whatever the filter; the labels of a page are one more (ResellerCustomers).
 */
class SoldServices
{
    public const PER_PAGE = 20;
    public const TAB_ALL = 'all';
    public const TAB_MINE = 'mine';
    public const TAB_CLAIMED = 'claimed';
    public const TAB_EXPIRING = 'expiring';
    public const TAB_ACTIVE = 'active';
    public const TAB_EXPIRED = 'expired';
    public const TAB_SUSPENDED = 'suspended';
    public const TABS = [self::TAB_ALL, self::TAB_MINE, self::TAB_CLAIMED, self::TAB_EXPIRING, self::TAB_ACTIVE, self::TAB_EXPIRED, self::TAB_SUSPENDED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param string      $tab        one of TABS
     * @param string|null $search     as typed: part of the service's username, "#id" / id, or part of his label's name
     * @param int         $customerId only the services of this label of his (0: any)
     *
     * @return array{items: list<VpnService>, page: int, hasNext: bool}
     */
    public function list(Reseller $reseller, string $tab, ?string $search, int $customerId, int $page): array
    {
        $page = max(1, min(10000, $page));
        $now = $this->clock->now();
        $query = $this->entityManager->createQueryBuilder()
            ->select('s', 'o', 'p')
            ->from(VpnService::class, 's')
            ->leftJoin('s.order', 'o')
            ->leftJoin('o.plan', 'p')
            ->where('s.soldByReseller = :reseller')
            ->andWhere('s.status <> :deleted')
            ->setParameter('reseller', $reseller)
            ->setParameter('deleted', VpnServiceStatus::DELETED);

        match ($tab) {
            self::TAB_MINE => $query->andWhere('s.user = :owner')->setParameter('owner', $reseller->getUser()),
            self::TAB_CLAIMED => $query->andWhere('s.user <> :owner')->setParameter('owner', $reseller->getUser()),
            self::TAB_EXPIRING => $query->andWhere('s.status = :active AND s.expiresAt >= :now AND s.expiresAt < :soon')
                ->setParameter('active', VpnServiceStatus::ACTIVE)
                ->setParameter('now', $now)
                ->setParameter('soon', $now->modify(sprintf('+%d days', ResellerDashboard::EXPIRING_DAYS))),
            self::TAB_ACTIVE, self::TAB_EXPIRED, self::TAB_SUSPENDED => $query->andWhere('s.status = :status')->setParameter('status', $tab),
            default => null,
        };
        if ($customerId > 0) {
            $query->andWhere('s.id IN (SELECT IDENTITY(fa.service) FROM '.ResellerCustomerService::class.' fa JOIN fa.customer fc WHERE fc.id = :customer AND fc.reseller = :reseller)')
                ->setParameter('customer', $customerId);
        }
        if (null !== $search) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $conditions = ['s.username LIKE :like', 's.id IN (SELECT IDENTITY(sa.service) FROM '.ResellerCustomerService::class.' sa JOIN sa.customer sc WHERE sc.reseller = :reseller AND sc.name LIKE :like)'];
            if (1 === preg_match('/^#?([1-9][0-9]{0,9})$/D', $search, $m)) {
                $conditions[] = 's.id = :searchId';
                $query->setParameter('searchId', (int) $m[1]);
            }
            $query->andWhere(implode(' OR ', $conditions))->setParameter('like', $like);
        }
        if (self::TAB_EXPIRING === $tab) {
            $query->orderBy('s.expiresAt', 'ASC')->addOrderBy('s.id', 'DESC');
        } else {
            $query->orderBy('s.id', 'DESC');
        }

        /** @var list<VpnService> $rows */
        $rows = $query->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE + 1)->getQuery()->getResult();

        return ['items' => array_slice($rows, 0, self::PER_PAGE), 'page' => $page, 'hasNext' => count($rows) > self::PER_PAGE];
    }

    /** Whether the service moved to someone else (a claim, or an admin's change): he only has the limited view then. */
    public function isClaimed(Reseller $reseller, VpnService $service): bool
    {
        return $service->getUser()->getId() !== $reseller->getUser()->getId();
    }

    /** His label of this service, if any. */
    public function labelOf(Reseller $reseller, VpnService $service): ?ResellerCustomer
    {
        $assignment = $this->entityManager->getRepository(ResellerCustomerService::class)->findOneBy(['service' => $service]);

        return $assignment instanceof ResellerCustomerService && $assignment->getCustomer()->getReseller()->getId() === $reseller->getId() ? $assignment->getCustomer() : null;
    }
}
