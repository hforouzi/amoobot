<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Entity\WalletTransaction;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use App\Shared\Infrastructure\TransactionRollback;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletKeys;
use App\Wallet\Domain\WalletReferenceType;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An admin's refund of a provisioned reseller order to the reseller's wallet (docs/design/reseller-and-claim.md, 4.2;
 * RESELLERS_MANAGE + WALLETS_MANAGE). Never automatic. Allowed only:
 *
 * - for a reseller order that was paid from the wallet and provisioned (before that, the existing reversal of the
 *   wallet payment gives the debit back: reject / cancel / expire / failed provisioning);
 * - once its service is suspended or deleted (the reseller's customer no longer has it);
 * - for at most the wholesale amount that order's wallet payment debited, once per order (the ledger key
 *   refund:order:{orderId} is unique: a repeated or simultaneous submit gets the first row back, or is refused);
 * - with a reason (3..500 characters). The audit entry (reseller.order_refund) is written in the same transaction as the
 *   ledger row: both exist or neither does.
 *
 * Lock order: the order row, then (WalletService) the user and the wallet: the order settlement uses too.
 */
final class ResellerRefunds
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WalletService $wallets,
        private readonly AdminAuditLogger $auditLogger,
        private readonly TransactionRollback $transactionRollback,
    ) {
    }

    /**
     * Why this refund cannot be made (a message key of the admin domain), or null when it can. Reads the database as
     * it is now.
     */
    public function problem(Order $order, mixed $amount, ?string $reason): ?string
    {
        $paid = $this->paidAmount($order);

        return match (true) {
            !$order->isResellerOrder() => 'reseller_admin.refund.error.not_reseller_order',
            OrderStatus::PROVISIONED !== $order->getStatus() || null === $paid => 'reseller_admin.refund.error.not_provisioned',
            null !== $this->wallets->findTransaction(WalletKeys::orderRefund((int) $order->getId())) => 'reseller_admin.refund.error.already_refunded',
            !$this->serviceIsStopped($order) => 'reseller_admin.refund.error.service_live',
            null === ($value = self::amount($amount)) || $value > $paid => 'reseller_admin.refund.error.amount',
            mb_strlen(trim((string) $reason)) < 3 || mb_strlen(trim((string) $reason)) > 500 => 'reseller_admin.refund.error.reason',
            default => null,
        };
    }

    /** The most that may be refunded: what the order's confirmed wallet payment debited (and was not given back). */
    public function paidAmount(Order $order): ?int
    {
        if (null === $order->getId()) {
            return null;
        }
        $debit = $this->entityManager->getConnection()->fetchOne(
            "SELECT -d.amount FROM payment p
             JOIN wallet_transaction d ON d.idempotency_key = CONCAT('purchase:payment:', p.id)
             LEFT JOIN wallet_transaction r ON r.reversal_of_id = d.id
             WHERE p.order_id = ? AND p.method = ? AND p.status = ? AND r.id IS NULL
             ORDER BY p.id LIMIT 1",
            [$order->getId(), Payment::METHOD_WALLET, PaymentStatus::CONFIRMED],
        );

        return false === $debit || null === $debit ? null : (int) $debit;
    }

    /**
     * For a page of orders, in two queries whatever its size (the reseller page): each order's refund already made
     * (refund:order:N, its amount) and, for the others, what may still be refunded (paidAmount()).
     *
     * @param list<Order> $orders
     *
     * @return array{refunded: array<int, int>, refundable: array<int, int>} by order id; missing = none
     */
    public function overview(array $orders): array
    {
        $ids = array_values(array_filter(array_map(static fn (Order $o): int => (int) $o->getId(), $orders)));
        if ([] === $ids) {
            return ['refunded' => [], 'refundable' => []];
        }
        $connection = $this->entityManager->getConnection();
        $orderByKey = [];
        foreach ($ids as $id) {
            $orderByKey[WalletKeys::orderRefund($id)] = $id;
        }
        $refunded = [];
        foreach ($connection->fetchAllAssociative('SELECT idempotency_key, amount FROM wallet_transaction WHERE idempotency_key IN (?)', [array_keys($orderByKey)], [ArrayParameterType::STRING]) as $row) {
            $refunded[$orderByKey[(string) $row['idempotency_key']]] = (int) $row['amount'];
        }
        $refundable = [];
        foreach ($connection->fetchAllAssociative(
            "SELECT p.order_id, -d.amount AS paid FROM payment p
             JOIN wallet_transaction d ON d.idempotency_key = CONCAT('purchase:payment:', p.id)
             LEFT JOIN wallet_transaction r ON r.reversal_of_id = d.id
             WHERE p.order_id IN (?) AND p.method = ? AND p.status = ? AND r.id IS NULL
             ORDER BY p.id",
            [$ids, Payment::METHOD_WALLET, PaymentStatus::CONFIRMED],
            [ArrayParameterType::INTEGER],
        ) as $row) {
            $refundable[(int) $row['order_id']] ??= (int) $row['paid'];
        }

        return ['refunded' => $refunded, 'refundable' => array_diff_key($refundable, $refunded)];
    }

    /**
     * @throws \InvalidArgumentException a problem() key: nothing was written
     */
    public function refund(Order $order, mixed $amount, string $reason, AdminActor $admin): WalletTransaction
    {
        $orderId = (int) $order->getId();
        $connection = $this->entityManager->getConnection();
        $this->entityManager->flush();
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();

        try {
            // First statement: the order lock (two admins refunding at once are serialized; the second sees the first).
            $connection->executeQuery('SELECT id FROM `order` WHERE id = ? FOR UPDATE', [$orderId]);
            $order = $this->entityManager->find(Order::class, $orderId) ?? throw new \InvalidArgumentException('reseller_admin.refund.error.not_reseller_order');
            $this->entityManager->refresh($order);
            if (null !== ($problem = $this->problem($order, $amount, $reason))) {
                throw new \InvalidArgumentException($problem);
            }
            $value = (int) self::amount($amount);
            $reason = trim($reason);
            $reseller = $order->getReseller();

            $transaction = $this->wallets->credit(
                $order->getUser(),
                $value,
                WalletTransactionType::REFUND,
                WalletKeys::orderRefund($orderId),
                WalletActor::admin($admin),
                WalletReferenceType::ORDER,
                (string) $orderId,
                $reason,
                fn (WalletTransaction $row) => $this->auditLogger->recordOrThrow('reseller.order_refund', 'Order', $orderId, [
                    'reseller_id' => $reseller?->getId(),
                    'customer_id' => $order->getUser()->getId(),
                    'order_type' => $order->getType(),
                    'amount' => $value,
                    'paid' => $this->paidAmount($order),
                    'reason' => $reason,
                    'transaction_id' => $row->getId(),
                    'balance_after' => $row->getBalanceAfter(),
                ], $admin),
            );
            $connection->commit();

            return $transaction;
        } catch (\Throwable $e) {
            if (!$this->transactionRollback->rollBack($level) || !$this->entityManager->isOpen()) {
                $this->transactionRollback->resetEntityManager();
            }

            throw $e;
        }
    }

    /** The order's service (the one it created, or the one it renewed / added traffic to) is suspended or deleted. */
    private function serviceIsStopped(Order $order): bool
    {
        $service = OrderType::NEW_SERVICE === $order->getType()
            ? $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order])
            : $order->getTargetService();
        if (!$service instanceof VpnService) {
            return false;
        }
        // The status as the database has it now (an earlier copy in this entity manager may be out of date).
        $this->entityManager->refresh($service);

        return in_array($service->getStatus(), [VpnServiceStatus::SUSPENDED, VpnServiceStatus::DELETED], true);
    }

    /** A positive whole number of Toman (up to WalletService::MAX_AMOUNT), else null. */
    private static function amount(mixed $amount): ?int
    {
        $value = is_int($amount) ? (string) $amount : (is_string($amount) ? trim(str_replace([',', '٬', ' '], '', $amount)) : '');
        if (1 !== preg_match('/^\d{1,13}$/D', $value) || (int) $value <= 0 || (int) $value > WalletService::MAX_AMOUNT) {
            return null;
        }

        return (int) $value;
    }
}
