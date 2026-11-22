<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Entity\Payment;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
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

    public function count(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Payment::class, 'p')
            ->where('p.status IN (:statuses)')
            ->andWhere('(p.gatewayType = :manual OR (p.gatewayType IS NULL AND p.method = :manual))')
            ->andWhere("((p.receiptFileId IS NOT NULL AND p.receiptFileId <> '') OR (p.trackingCode IS NOT NULL AND p.trackingCode <> ''))")
            ->setParameter('statuses', [PaymentStatus::PENDING, PaymentStatus::SUBMITTED])
            ->setParameter('manual', PaymentGatewayType::MANUAL_CARD)
            ->getQuery()
            ->getSingleScalarResult();
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
