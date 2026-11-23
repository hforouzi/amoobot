<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Entity\Payment;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Card-to-card receipts waiting for an admin (docs/design/admin-redesign.md, 4.1, decision 5): pending or submitted
 * card-to-card payments (ReceiptSubmission::isManual()) that carry a receipt photo or a tracking code. A receipt does
 * not change the payment's status (ReceiptSubmission), so this is its own query, not a status tab count. This count,
 * and only this, is the red badge of "Sales & payments" in the modern sidebar; "needs review" (a gateway amount
 * mismatch) is never part of it.
 */
final class CardReceiptQueue
{
    /** The badge is on every admin page: one COUNT, shared by all admins for this long (4.5). */
    public const CACHE_SECONDS = 30;
    public const CACHE_KEY = 'admin_ui_card_receipts_waiting';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
    ) {
    }

    public static function apply(QueryBuilder $qb, string $alias): QueryBuilder
    {
        return $qb->andWhere("$alias.status IN (:receiptStatuses)")
            ->andWhere("($alias.gatewayType = :receiptManual OR ($alias.gatewayType IS NULL AND $alias.method = :receiptManual))")
            ->andWhere("(($alias.receiptFileId IS NOT NULL AND $alias.receiptFileId <> '') OR ($alias.trackingCode IS NOT NULL AND $alias.trackingCode <> ''))")
            ->setParameter('receiptStatuses', [PaymentStatus::PENDING, PaymentStatus::SUBMITTED])
            ->setParameter('receiptManual', PaymentGatewayType::MANUAL_CARD);
    }

    public function count(): int
    {
        return (int) self::apply($this->entityManager->createQueryBuilder()->select('COUNT(p.id)')->from(Payment::class, 'p'), 'p')
            ->getQuery()->getSingleScalarResult();
    }

    /** count(), cached for CACHE_SECONDS. */
    public function cachedCount(): int
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): int {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->count();
        });
    }
}
