<?php

declare(strict_types=1);

namespace App\Wallet\Application;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Checks every wallet's cached balance against its ledger: the balance must equal the sum of the ledger amounts,
 * and the last ledger row's balance_after must equal the balance; every row's balance_after must equal the running
 * sum up to it. Read only: it reports, it never changes a wallet or a ledger row.
 */
final class WalletReconciler
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
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
}
