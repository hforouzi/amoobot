<?php

declare(strict_types=1);

namespace App\Wallet\Application;

use App\Entity\Payment;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Checks the wallets. Read only: it reports, it never changes a wallet, a ledger row or a payment.
 *
 * mismatches(): every wallet's cached balance against its ledger: the balance must equal the sum of the ledger
 * amounts, and the last ledger row's balance_after must equal the balance; every row's balance_after must equal the
 * running sum up to it.
 *
 * paymentMismatches(): payments against the ledger, through the ledger keys the payment flows write (WalletKeys):
 * a confirmed top-up without its credit, a top-up credit whose payment is not confirmed, a live wallet payment without
 * its debit, a rejected payment whose debit was never given back, a debit given back while its payment is still live,
 * a purchase debit without a payment, and a wallet payment still pending (its debit held) while its order is no
 * longer waiting for payment (paid otherwise, cancelled, expired: nothing would ever give that debit back).
 *
 * report() runs both in one read-only transaction, so every query sees the same snapshot even while payments are
 * being settled (no false alarm from a write that landed between two queries).
 */
final class WalletReconciler
{
    public const TOPUP_NOT_CREDITED = 'topup_not_credited';
    public const TOPUP_CREDIT_WITHOUT_CONFIRMED_PAYMENT = 'topup_credit_without_confirmed_payment';
    public const WALLET_PAYMENT_WITHOUT_DEBIT = 'wallet_payment_without_debit';
    public const DEBIT_NOT_RETURNED = 'debit_not_returned';
    public const REVERSAL_OF_LIVE_PAYMENT = 'reversal_of_live_payment';
    public const PURCHASE_WITHOUT_PAYMENT = 'purchase_without_payment';
    public const PENDING_WALLET_PAYMENT_ORDER_NOT_WAITING = 'pending_wallet_payment_order_not_waiting';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{balances: list<array{walletId: int, userId: int, problem: string, cached: int, expected: int}>, payments: list<array{problem: string, paymentId: int|null, transactionId: int|null, walletId: int|null, detail: string}>}
     */
    public function report(): array
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            return ['balances' => $this->mismatches(), 'payments' => $this->paymentMismatches()];
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @return list<array{walletId: int, userId: int, problem: string, cached: int, expected: int}>
     */
    public function mismatches(): array
    {
        $connection = $this->entityManager->getConnection();
        $mismatches = [];

        $rows = $connection->fetchAllAssociative(
            'SELECT w.id, w.user_id, w.balance, COALESCE(SUM(t.amount), 0) AS ledger_sum,
                    (SELECT l.balance_after FROM wallet_transaction l WHERE l.wallet_id = w.id ORDER BY l.id DESC LIMIT 1) AS last_balance_after
             FROM wallet w LEFT JOIN wallet_transaction t ON t.wallet_id = w.id
             GROUP BY w.id, w.user_id, w.balance
             ORDER BY w.id'
        );
        foreach ($rows as $row) {
            $base = ['walletId' => (int) $row['id'], 'userId' => (int) $row['user_id'], 'cached' => (int) $row['balance']];
            if ((int) $row['balance'] !== (int) $row['ledger_sum']) {
                $mismatches[] = $base + ['problem' => 'balance', 'expected' => (int) $row['ledger_sum']];
            }
            if (null !== $row['last_balance_after'] && (int) $row['balance'] !== (int) $row['last_balance_after']) {
                $mismatches[] = $base + ['problem' => 'last_balance_after', 'expected' => (int) $row['last_balance_after']];
            }
        }

        // Every row's running balance (ledger rows are in id order per wallet).
        $running = [];
        $result = $connection->executeQuery('SELECT t.id, t.wallet_id, w.user_id, t.amount, t.balance_after FROM wallet_transaction t JOIN wallet w ON w.id = t.wallet_id ORDER BY t.wallet_id, t.id');
        while (false !== ($row = $result->fetchAssociative())) {
            $walletId = (int) $row['wallet_id'];
            $running[$walletId] = ($running[$walletId] ?? 0) + (int) $row['amount'];
            if ($running[$walletId] !== (int) $row['balance_after']) {
                $mismatches[] = ['walletId' => $walletId, 'userId' => (int) $row['user_id'], 'problem' => sprintf('balance_after of transaction #%d', (int) $row['id']), 'cached' => (int) $row['balance_after'], 'expected' => $running[$walletId]];
                // Continue from the stored value so one bad row is reported once, not for every later row.
                $running[$walletId] = (int) $row['balance_after'];
            }
        }

        return $mismatches;
    }

    /**
     * @return list<array{problem: string, paymentId: int|null, transactionId: int|null, walletId: int|null, detail: string}>
     */
    public function paymentMismatches(): array
    {
        $connection = $this->entityManager->getConnection();
        $parameters = [
            'confirmed' => PaymentStatus::CONFIRMED,
            'rejected' => PaymentStatus::REJECTED,
            'walletTopUp' => OrderType::WALLET_TOPUP,
            'wallet' => Payment::METHOD_WALLET,
            'topup' => WalletTransactionType::TOPUP,
            'purchase' => WalletTransactionType::PURCHASE,
            'pending' => PaymentStatus::PENDING,
            'submitted' => PaymentStatus::SUBMITTED,
            'waitingPayment' => OrderStatus::WAITING_PAYMENT,
        ];
        // The ledger keys of the payment flows (WalletKeys): 'topup:payment:<id>' and 'purchase:payment:<id>'.
        $topUpKey = "CONCAT('topup:payment:', p.id)";
        $purchaseKey = "CONCAT('purchase:payment:', p.id)";

        $checks = [
            self::TOPUP_NOT_CREDITED => "SELECT p.id AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('confirmed top-up of order #', o.id, ' has no credit in the ledger') AS detail
                 FROM payment p JOIN `order` o ON o.id = p.order_id
                 LEFT JOIN wallet_transaction c ON c.idempotency_key = $topUpKey
                 WHERE o.type = :walletTopUp AND p.status = :confirmed AND c.id IS NULL",
            self::TOPUP_CREDIT_WITHOUT_CONFIRMED_PAYMENT => "SELECT p.id AS payment_id, c.id AS transaction_id, c.wallet_id, CONCAT('credit of ', c.amount, ' but the payment is ', COALESCE(p.status, 'missing')) AS detail
                 FROM wallet_transaction c
                 LEFT JOIN payment p ON p.id = CAST(SUBSTRING(c.idempotency_key, 15) AS UNSIGNED) AND c.idempotency_key = $topUpKey
                 WHERE c.type = :topup AND c.idempotency_key LIKE 'topup:payment:%' AND (p.id IS NULL OR p.status <> :confirmed)",
            self::WALLET_PAYMENT_WITHOUT_DEBIT => "SELECT p.id AS payment_id, NULL AS transaction_id, NULL AS wallet_id, CONCAT('wallet payment is ', p.status, ' and has no debit in the ledger') AS detail
                 FROM payment p
                 LEFT JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 WHERE p.method = :wallet AND p.status <> :rejected AND d.id IS NULL",
            self::DEBIT_NOT_RETURNED => "SELECT p.id AS payment_id, d.id AS transaction_id, d.wallet_id, CONCAT('payment is rejected; debit of ', -d.amount, ' was never given back') AS detail
                 FROM payment p
                 JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 LEFT JOIN wallet_transaction r ON r.reversal_of_id = d.id
                 WHERE p.status = :rejected AND r.id IS NULL",
            self::REVERSAL_OF_LIVE_PAYMENT => "SELECT p.id AS payment_id, r.id AS transaction_id, r.wallet_id, CONCAT('debit given back but the payment is ', p.status) AS detail
                 FROM payment p
                 JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 JOIN wallet_transaction r ON r.reversal_of_id = d.id
                 WHERE p.status <> :rejected",
            self::PURCHASE_WITHOUT_PAYMENT => "SELECT NULL AS payment_id, d.id AS transaction_id, d.wallet_id, CONCAT('purchase debit ', d.idempotency_key, ' has no payment') AS detail
                 FROM wallet_transaction d
                 LEFT JOIN payment p ON p.id = CAST(SUBSTRING(d.idempotency_key, 18) AS UNSIGNED) AND d.idempotency_key = $purchaseKey
                 WHERE d.type = :purchase AND p.id IS NULL",
            self::PENDING_WALLET_PAYMENT_ORDER_NOT_WAITING => "SELECT p.id AS payment_id, d.id AS transaction_id, d.wallet_id, CONCAT('wallet payment is ', p.status, ' but order #', o.id, ' is ', o.status) AS detail
                 FROM payment p JOIN `order` o ON o.id = p.order_id
                 LEFT JOIN wallet_transaction d ON d.idempotency_key = $purchaseKey
                 WHERE p.method = :wallet AND p.status IN (:pending, :submitted) AND o.status <> :waitingPayment",
        ];

        $mismatches = [];
        foreach ($checks as $problem => $sql) {
            foreach ($this->rows($connection, $sql, $parameters) as $row) {
                $mismatches[] = [
                    'problem' => $problem,
                    'paymentId' => null === $row['payment_id'] ? null : (int) $row['payment_id'],
                    'transactionId' => null === $row['transaction_id'] ? null : (int) $row['transaction_id'],
                    'walletId' => null === $row['wallet_id'] ? null : (int) $row['wallet_id'],
                    'detail' => (string) $row['detail'],
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Only the named parameters a query uses (DBAL refuses unused ones).
     *
     * @param array<string, string> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Connection $connection, string $sql, array $parameters): array
    {
        $used = array_filter($parameters, static fn (string $name): bool => 1 === preg_match('/:'.$name.'\b/', $sql), ARRAY_FILTER_USE_KEY);

        return $connection->fetchAllAssociative($sql.' ORDER BY 1, 2', $used);
    }
}
