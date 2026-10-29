<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Reseller;
use App\Kernel;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Reseller\Domain\ClaimStatus;
use App\Shop\Domain\OrderStatus;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The reseller's own figures for his Mini App dashboard (docs/design/reseller-and-claim.md, 6.2), read only. A fixed
 * number of queries whatever the number of orders and services (aggregates and one short list), never one per row.
 *
 * Sales are his reseller orders whose wallet payment is confirmed (as in ResellerReport), by the time they were paid:
 * wholesale = what he paid (order.amount), retail value = what a retail customer would have paid then
 * (order.retail_amount), margin = retail value - wholesale. Refunds of his orders are shown on their own line, by the
 * time they were made, and taken out of both: the wholesale given back, and the same share of the order's retail
 * value (so a fully refunded order adds nothing to the margin). His real resale price is unknown to the shop, so the margin
 * is measured against the shop's retail price, not his profit. "This month" starts on the 1st, 00:00 in the application
 * timezone (Kernel::TIMEZONE), like the daily cap.
 */
class ResellerDashboard
{
    public const RECENT_ORDERS = 5;
    /** "Expiring soon" on the dashboard and in the sold-services filter. */
    public const EXPIRING_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{month: array{orders: int, refunds: int, wholesale: int, retail: int, margin: int}, total: array{orders: int, refunds: int, wholesale: int, retail: int, margin: int}}
     */
    public function sales(Reseller $reseller): array
    {
        $monthStart = $this->clock->now()->setTimezone(new \DateTimeZone(Kernel::TIMEZONE))->modify('first day of this month')->setTime(0, 0);
        $connection = $this->entityManager->getConnection();
        $parameters = ['reseller' => $reseller->getId(), 'month' => $monthStart->format('Y-m-d H:i:s')];
        $row = $connection->fetchAssociative(
            'SELECT COUNT(*) AS orders,
                    COALESCE(SUM(o.amount), 0) AS wholesale,
                    COALESCE(SUM(COALESCE(o.retail_amount, o.amount)), 0) AS retail,
                    COALESCE(SUM(o.paid_at >= :month), 0) AS month_orders,
                    COALESCE(SUM(CASE WHEN o.paid_at >= :month THEN o.amount ELSE 0 END), 0) AS month_wholesale,
                    COALESCE(SUM(CASE WHEN o.paid_at >= :month THEN COALESCE(o.retail_amount, o.amount) ELSE 0 END), 0) AS month_retail
             FROM `order` o
             WHERE o.reseller_id = :reseller AND o.paid_at IS NOT NULL
               AND EXISTS (SELECT 1 FROM payment p WHERE p.order_id = o.id AND p.status = :confirmed)',
            $parameters + ['confirmed' => PaymentStatus::CONFIRMED],
        ) ?: [];
        // Refunds of his orders (refund:order:N, as ResellerReport counts them), by the time they were made: the
        // wholesale given back, and the same share of the order's retail value (a full refund takes the whole sale out).
        $refunds = $connection->fetchAssociative(
            "SELECT COALESCE(SUM(t.amount), 0) AS refunds,
                    COALESCE(SUM(ROUND(t.amount * COALESCE(o.retail_amount, o.amount) / o.amount)), 0) AS retail,
                    COALESCE(SUM(CASE WHEN t.created_at >= :month THEN t.amount ELSE 0 END), 0) AS month_refunds,
                    COALESCE(SUM(CASE WHEN t.created_at >= :month THEN ROUND(t.amount * COALESCE(o.retail_amount, o.amount) / o.amount) ELSE 0 END), 0) AS month_retail
             FROM wallet_transaction t JOIN `order` o ON t.idempotency_key = CONCAT('refund:order:', o.id)
             WHERE t.type = :refund AND o.reseller_id = :reseller AND o.amount > 0",
            $parameters + ['refund' => WalletTransactionType::REFUND],
        ) ?: [];
        $figures = static function (int $orders, int $wholesale, int $retail, int $refunds, int $refundedRetail): array {
            $wholesale -= $refunds;
            $retail -= $refundedRetail;

            return ['orders' => $orders, 'refunds' => $refunds, 'wholesale' => $wholesale, 'retail' => $retail, 'margin' => max(0, $retail - $wholesale)];
        };

        return [
            'month' => $figures((int) ($row['month_orders'] ?? 0), (int) ($row['month_wholesale'] ?? 0), (int) ($row['month_retail'] ?? 0), (int) ($refunds['month_refunds'] ?? 0), (int) ($refunds['month_retail'] ?? 0)),
            'total' => $figures((int) ($row['orders'] ?? 0), (int) ($row['wholesale'] ?? 0), (int) ($row['retail'] ?? 0), (int) ($refunds['refunds'] ?? 0), (int) ($refunds['retail'] ?? 0)),
        ];
    }

    /**
     * His sold services that are not deleted: all, still his (not claimed), claimed by a customer, and still his or
     * claimed but active and expiring within EXPIRING_DAYS. One query.
     *
     * @return array{sold: int, unclaimed: int, claimed: int, expiring: int}
     */
    public function serviceCounts(Reseller $reseller): array
    {
        $now = $this->clock->now();
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS sold,
                    COALESCE(SUM(s.user_id = :owner), 0) AS unclaimed,
                    COALESCE(SUM(s.user_id <> :owner), 0) AS claimed,
                    COALESCE(SUM(s.status = :active AND s.expires_at IS NOT NULL AND s.expires_at >= :now AND s.expires_at < :soon), 0) AS expiring
             FROM vpn_service s
             WHERE s.sold_by_reseller_id = :reseller AND s.status <> :deleted',
            [
                'reseller' => $reseller->getId(),
                'owner' => $reseller->getUser()->getId(),
                'active' => VpnServiceStatus::ACTIVE,
                'deleted' => VpnServiceStatus::DELETED,
                'now' => $now->format('Y-m-d H:i:s'),
                'soon' => $now->modify(sprintf('+%d days', self::EXPIRING_DAYS))->format('Y-m-d H:i:s'),
            ],
        ) ?: [];

        return ['sold' => (int) ($row['sold'] ?? 0), 'unclaimed' => (int) ($row['unclaimed'] ?? 0), 'claimed' => (int) ($row['claimed'] ?? 0), 'expiring' => (int) ($row['expiring'] ?? 0)];
    }

    /** His claim links that can still be used. */
    public function openLinks(Reseller $reseller): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM service_claim WHERE reseller_id = ? AND status = ?', [$reseller->getId(), ClaimStatus::OPEN]);
    }

    /**
     * His latest reseller orders, any status (one query; the order screen is the existing one).
     *
     * @return list<array{id: int, type: string, status: string, amount: int, retail: int, createdAt: \DateTimeImmutable, serviceId: int|null, trackingCode: string|null}>
     */
    public function recentOrders(Reseller $reseller, int $limit = self::RECENT_ORDERS): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT o.id, o.type, o.status, o.amount, COALESCE(o.retail_amount, o.amount) AS retail, o.created_at, o.target_service_id, o.tracking_code
             FROM `order` o WHERE o.reseller_id = ? AND o.status NOT IN (?, ?) ORDER BY o.id DESC LIMIT '.max(1, min(20, $limit)),
            [$reseller->getId(), OrderStatus::DRAFT, OrderStatus::PENDING],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'status' => (string) $row['status'],
            'amount' => (int) $row['amount'],
            'retail' => (int) $row['retail'],
            'createdAt' => new \DateTimeImmutable((string) $row['created_at']),
            'serviceId' => null === $row['target_service_id'] ? null : (int) $row['target_service_id'],
            'trackingCode' => null === $row['tracking_code'] ? null : (string) $row['tracking_code'],
        ], $rows);
    }
}
