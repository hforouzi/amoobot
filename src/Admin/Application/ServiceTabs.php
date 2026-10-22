<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Entity\VpnService;
use App\Provisioning\Domain\VpnServiceStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;

/**
 * The service list's tabs, shared by the web list (VpnServiceCrudController), the dashboards and the Mini App.
 * Deleted services are never listed. LOW_TRAFFIC is an extra filter (Mini App): active, with a limit, and at most
 * LOW_TRAFFIC_PERCENT of it left.
 */
final class ServiceTabs
{
    public const TABS = ['all', 'active', 'expiring', 'expired', 'suspended'];
    public const LOW_TRAFFIC = 'low_traffic';
    public const EXPIRING_DAYS = 3;
    public const LOW_TRAFFIC_PERCENT = 10;

    /**
     * @param list<string> $allowed
     */
    public static function normalize(?string $tab, array $allowed = self::TABS): string
    {
        return null !== $tab && in_array($tab, $allowed, true) ? $tab : 'all';
    }

    /**
     * Leaves deleted services out and filters a service query (root alias $alias) to the tab.
     */
    public static function apply(QueryBuilder $qb, string $alias, string $tab, \DateTimeImmutable $now): QueryBuilder
    {
        $qb->andWhere(sprintf('%s.status != :deletedStatus', $alias))->setParameter('deletedStatus', VpnServiceStatus::DELETED);
        $e = $alias;
        match ($tab) {
            'active' => $qb->andWhere("$e.status = :tabStatus AND ($e.expiresAt IS NULL OR $e.expiresAt > :tabNow)")->setParameter('tabStatus', VpnServiceStatus::ACTIVE)->setParameter('tabNow', $now),
            'expiring' => $qb->andWhere("$e.status = :tabStatus AND $e.expiresAt > :tabNow AND $e.expiresAt <= :tabSoon")->setParameter('tabStatus', VpnServiceStatus::ACTIVE)->setParameter('tabNow', $now)->setParameter('tabSoon', $now->modify('+'.self::EXPIRING_DAYS.' days')),
            'expired' => $qb->andWhere("$e.status = :tabStatus")->setParameter('tabStatus', VpnServiceStatus::EXPIRED),
            'suspended' => $qb->andWhere("$e.status = :tabStatus")->setParameter('tabStatus', VpnServiceStatus::SUSPENDED),
            self::LOW_TRAFFIC => $qb->andWhere("$e.status = :tabStatus AND $e.trafficLimitBytes > 0 AND ($e.trafficLimitBytes - COALESCE($e.trafficUsedBytes, 0)) * 100 <= $e.trafficLimitBytes * :lowPercent")->setParameter('tabStatus', VpnServiceStatus::ACTIVE)->setParameter('lowPercent', self::LOW_TRAFFIC_PERCENT),
            default => null,
        };

        return $qb;
    }

    /**
     * Services per tab (deleted services are never listed), in one query.
     *
     * @return array<string, int>
     */
    public static function countByTab(EntityManagerInterface $entityManager, \DateTimeImmutable $now): array
    {
        $row = $entityManager->createQueryBuilder()
            ->select(
                'COUNT(s.id) AS all_count',
                'SUM(CASE WHEN s.status = :active AND (s.expiresAt IS NULL OR s.expiresAt > :now) THEN 1 ELSE 0 END) AS active_count',
                'SUM(CASE WHEN s.status = :active AND s.expiresAt > :now AND s.expiresAt <= :soon THEN 1 ELSE 0 END) AS expiring_count',
                'SUM(CASE WHEN s.status = :expired THEN 1 ELSE 0 END) AS expired_count',
                'SUM(CASE WHEN s.status = :suspended THEN 1 ELSE 0 END) AS suspended_count',
            )
            ->from(VpnService::class, 's')
            ->andWhere('s.status != :deleted')
            ->setParameters(new ArrayCollection([
                new Parameter('active', VpnServiceStatus::ACTIVE),
                new Parameter('expired', VpnServiceStatus::EXPIRED),
                new Parameter('suspended', VpnServiceStatus::SUSPENDED),
                new Parameter('deleted', VpnServiceStatus::DELETED),
                new Parameter('now', $now),
                new Parameter('soon', $now->modify('+'.self::EXPIRING_DAYS.' days')),
            ]))
            ->getQuery()
            ->getSingleResult();

        return [
            'all' => (int) $row['all_count'],
            'active' => (int) $row['active_count'],
            'expiring' => (int) $row['expiring_count'],
            'expired' => (int) $row['expired_count'],
            'suspended' => (int) $row['suspended_count'],
        ];
    }
}
