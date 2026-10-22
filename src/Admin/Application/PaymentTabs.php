<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * The payment list's status tabs, shared by the web list (PaymentCrudController), the dashboards and the Mini App:
 * which statuses a tab holds, how many payments each holds, and the tab's filter and order on a list query.
 */
final class PaymentTabs
{
    /** Tab => the statuses it lists ("all": no filter). */
    public const TABS = [
        'all' => [],
        'pending' => [PaymentStatus::PENDING, PaymentStatus::SUBMITTED],
        'needs_review' => [PaymentStatus::NEEDS_REVIEW],
        'provisioning_failed' => [PaymentStatus::PROVISIONING_FAILED],
        'confirmed' => [PaymentStatus::CONFIRMED],
        'rejected' => [PaymentStatus::REJECTED],
    ];

    /** Tabs an admin works through: oldest first (as the old "needs attention" lists). */
    public const OLDEST_FIRST = ['needs_review', 'provisioning_failed'];

    public static function normalize(?string $tab): string
    {
        return null !== $tab && array_key_exists($tab, self::TABS) ? $tab : 'all';
    }

    /**
     * Filters a payment query (root alias $alias) to the tab; with $order, also sorts it (oldest first on the tabs an
     * admin works through, else newest first).
     */
    public static function apply(QueryBuilder $qb, string $alias, string $tab, bool $order = true): QueryBuilder
    {
        $tab = self::normalize($tab);
        if ([] !== self::TABS[$tab]) {
            $qb->andWhere(sprintf('%s.status IN (:tabStatuses)', $alias))->setParameter('tabStatuses', self::TABS[$tab]);
        }
        if ($order) {
            $qb->orderBy($alias.'.id', in_array($tab, self::OLDEST_FIRST, true) ? 'ASC' : 'DESC');
        }

        return $qb;
    }

    /**
     * Payments per status tab, in one query.
     *
     * @return array<string, int>
     */
    public static function countByTab(EntityManagerInterface $entityManager): array
    {
        $byStatus = [];
        foreach ($entityManager->createQueryBuilder()->select('p.status AS status', 'COUNT(p.id) AS total')->from(Payment::class, 'p')->groupBy('p.status')->getQuery()->getArrayResult() as $row) {
            $byStatus[(string) $row['status']] = (int) $row['total'];
        }

        $counts = [];
        foreach (self::TABS as $tab => $statuses) {
            $counts[$tab] = [] === $statuses ? array_sum($byStatus) : array_sum(array_map(static fn (string $status): int => $byStatus[$status] ?? 0, $statuses));
        }

        return $counts;
    }
}
