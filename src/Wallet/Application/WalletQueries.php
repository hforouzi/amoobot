<?php

declare(strict_types=1);

namespace App\Wallet\Application;

use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read side of the wallets (customer views, admin lists, dashboard). Never writes.
 */
final class WalletQueries
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * One page of a wallet's ledger, newest first.
     *
     * @return array{items: list<WalletTransaction>, hasNext: bool}
     */
    public function transactions(Wallet $wallet, int $page, int $perPage): array
    {
        $page = max(1, min(10000, $page));
        /** @var list<WalletTransaction> $rows */
        $rows = $this->entityManager->getRepository(WalletTransaction::class)->findBy(['wallet' => $wallet], ['id' => 'DESC'], $perPage + 1, ($page - 1) * $perPage);

        return ['items' => array_slice($rows, 0, $perPage), 'hasNext' => count($rows) > $perPage];
    }

    /**
     * Wallets for the admin list, most recently active first, optionally only those with money or credit in play.
     *
     * @return array{items: list<Wallet>, hasNext: bool}
     */
    public function wallets(int $page, int $perPage, ?string $search = null): array
    {
        $page = max(1, min(10000, $page));
        $qb = $this->entityManager->getRepository(Wallet::class)->createQueryBuilder('w')
            ->join('w.user', 'u')->addSelect('u')
            ->leftJoin('u.telegramAccount', 'ta')->addSelect('ta')
            ->orderBy('w.lastActivityAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setMaxResults($perPage + 1)
            ->setFirstResult(($page - 1) * $perPage);
        $search = trim((string) $search);
        if ('' !== $search) {
            $qb->andWhere('ta.telegramId = :exact OR ta.username LIKE :like OR u.name LIKE :like OR u.mobile LIKE :like OR u.id = :id')
                ->setParameter('exact', ltrim($search, '@'))
                ->setParameter('like', '%'.addcslashes(ltrim($search, '@'), '%_').'%')
                ->setParameter('id', ctype_digit($search) ? (int) $search : 0);
        }
        /** @var list<Wallet> $rows */
        $rows = $qb->getQuery()->getResult();

        return ['items' => array_slice($rows, 0, $perPage), 'hasNext' => count($rows) > $perPage];
    }

    /**
     * Totals for the dashboard: what customers hold (positive balances) and what the shop has lent (negative
     * balances = used credit), plus the credit granted.
     *
     * @return array{outstanding: int, creditUsed: int, creditLimit: int, wallets: int}
     */
    public function totals(): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS outstanding,
                    COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END), 0) AS credit_used,
                    COALESCE(SUM(credit_limit), 0) AS credit_limit,
                    COUNT(*) AS wallets
             FROM wallet'
        ) ?: [];

        return [
            'outstanding' => (int) ($row['outstanding'] ?? 0),
            'creditUsed' => (int) ($row['credit_used'] ?? 0),
            'creditLimit' => (int) ($row['credit_limit'] ?? 0),
            'wallets' => (int) ($row['wallets'] ?? 0),
        ];
    }
}
