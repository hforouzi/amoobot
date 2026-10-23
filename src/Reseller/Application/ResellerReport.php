<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Reseller;
use App\Payment\Domain\PaymentStatus;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Per-reseller figures for the admin report (RESELLERS_VIEW), read only. Counts the reseller orders whose wallet payment
 * is confirmed, by the time the order was paid: wholesale = what the reseller paid (order.amount), retail value = what
 * a retail customer would have paid then (order.retail_amount), discount given = retail - wholesale. The reseller's
 * real resale price is unknown to the shop, so this is not his profit. Refunds (refund:order:N) are counted by the
 * time they were made.
 */
final class ResellerReport
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return list<array{resellerId: int, name: string, orders: int, newServices: int, renewals: int, addTraffic: int, wholesale: int, retail: int, discountGiven: int, refunds: int}>
     */
    public function summary(\DateTimeImmutable $from, \DateTimeImmutable $to, ?Reseller $only = null): array
    {
        $connection = $this->entityManager->getConnection();
        $range = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        $filter = null === $only ? '' : ' AND o.reseller_id = :reseller';
        $parameters = $range + ['confirmed' => PaymentStatus::CONFIRMED] + (null === $only ? [] : ['reseller' => $only->getId()]);

        $orders = $connection->fetchAllAssociative(
            "SELECT o.reseller_id,
                    COUNT(*) AS orders,
                    SUM(o.type = 'new_service') AS new_services,
                    SUM(o.type = 'renewal') AS renewals,
                    SUM(o.type = 'add_traffic') AS add_traffic,
                    SUM(o.amount) AS wholesale,
                    SUM(COALESCE(o.retail_amount, o.amount)) AS retail
             FROM `order` o
             WHERE o.reseller_id IS NOT NULL AND o.paid_at >= :from AND o.paid_at < :to$filter
               AND EXISTS (SELECT 1 FROM payment p WHERE p.order_id = o.id AND p.status = :confirmed)
             GROUP BY o.reseller_id",
            $parameters,
        );
        $refunds = $connection->fetchAllKeyValue(
            "SELECT o.reseller_id, SUM(t.amount)
             FROM wallet_transaction t JOIN `order` o ON t.idempotency_key = CONCAT('refund:order:', o.id)
             WHERE t.type = :refund AND t.created_at >= :from AND t.created_at < :to$filter
             GROUP BY o.reseller_id",
            $range + ['refund' => WalletTransactionType::REFUND] + (null === $only ? [] : ['reseller' => $only->getId()]),
        );

        $byReseller = [];
        foreach ($orders as $row) {
            $byReseller[(int) $row['reseller_id']] = $row;
        }
        $ids = array_unique([...array_keys($byReseller), ...array_map('intval', array_keys($refunds))]);
        $resellers = [] === $ids ? [] : $this->entityManager->getRepository(Reseller::class)->findBy(['id' => $ids]);

        $summary = [];
        foreach ($resellers as $reseller) {
            $id = (int) $reseller->getId();
            $row = $byReseller[$id] ?? [];
            $wholesale = (int) ($row['wholesale'] ?? 0);
            $retail = (int) ($row['retail'] ?? 0);
            $summary[] = [
                'resellerId' => $id,
                'name' => (string) $reseller,
                'orders' => (int) ($row['orders'] ?? 0),
                'newServices' => (int) ($row['new_services'] ?? 0),
                'renewals' => (int) ($row['renewals'] ?? 0),
                'addTraffic' => (int) ($row['add_traffic'] ?? 0),
                'wholesale' => $wholesale,
                'retail' => $retail,
                'discountGiven' => max(0, $retail - $wholesale),
                'refunds' => (int) ($refunds[$id] ?? 0),
            ];
        }
        usort($summary, static fn (array $a, array $b): int => [$b['wholesale'], $a['resellerId']] <=> [$a['wholesale'], $b['resellerId']]);

        return $summary;
    }
}
