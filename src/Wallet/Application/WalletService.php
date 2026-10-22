<?php

declare(strict_types=1);

namespace App\Wallet\Application;

use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Wallet\Domain\InsufficientWalletBalance;
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletFrozen;
use App\Wallet\Domain\WalletIdempotencyConflict;
use App\Wallet\Domain\WalletStatus;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * The ONLY writer of wallets and their ledger (WalletTransaction).
 *
 * Every write runs in a database transaction (a savepoint when the caller already has one, e.g. a payment
 * settlement) and holds a PESSIMISTIC_WRITE lock on the wallet row while it reads the balance, checks it and appends
 * the ledger row with the new balance. So two concurrent debits are serialized and the second one sees the first
 * one's balance: the wallet can never be overdrawn past its credit limit.
 *
 * Every movement carries an idempotency key, unique in the ledger: a retry with the same key returns the row that
 * was written the first time and changes nothing (the same key for a different movement is refused). A row can be
 * reversed at most once (unique reversalOf).
 *
 * Lock order: the customer's user row, then the wallet row (see lock()). Callers that also lock orders/payments
 * (PaymentSettlementService, WalletPayment) take those first, so the two never deadlock.
 */
class WalletService
{
    /** 1,000,000,000,000 Toman: a sanity bound, far above any real amount. */
    public const MAX_AMOUNT = 1_000_000_000_000;
    private const KEY_PATTERN = '/^[A-Za-z0-9:._-]{1,128}$/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** The user's wallet, or null when it was never used (read only: nothing is created). */
    public function find(User $user): ?Wallet
    {
        $wallet = $this->entityManager->getRepository(Wallet::class)->findOneBy(['user' => $user]);

        return $wallet instanceof Wallet ? $wallet : null;
    }

    /** The user's wallet, created (empty, active, no credit) on first use. */
    public function walletFor(User $user): Wallet
    {
        return $this->find($user) ?? $this->changeWallet($this->userId($user), static function (): void {
        }, ['action' => 'create']);
    }

    public function findTransaction(string $idempotencyKey): ?WalletTransaction
    {
        $transaction = $this->entityManager->getRepository(WalletTransaction::class)->findOneBy(['idempotencyKey' => $idempotencyKey]);

        return $transaction instanceof WalletTransaction ? $transaction : null;
    }

    /**
     * Adds money. Never refused for a frozen wallet (money already paid must not be lost).
     *
     * @param int $amount positive, integer Toman
     */
    public function credit(User $user, int $amount, string $type, string $idempotencyKey, WalletActor $by, ?string $referenceType = null, ?string $referenceId = null, ?string $note = null): WalletTransaction
    {
        $this->assertMovement($amount, $type, $idempotencyKey);

        return $this->write($this->userId($user), $amount, $type, $idempotencyKey, $by, $referenceType, $referenceId, $note);
    }

    /**
     * Takes money. Refused when balance + credit limit would go below zero (InsufficientWalletBalance), and for a
     * PURCHASE from a frozen wallet (WalletFrozen). Nothing is written when refused.
     *
     * @param int $amount positive, integer Toman
     */
    public function debit(User $user, int $amount, string $type, string $idempotencyKey, WalletActor $by, ?string $referenceType = null, ?string $referenceId = null, ?string $note = null): WalletTransaction
    {
        $this->assertMovement($amount, $type, $idempotencyKey);

        return $this->write($this->userId($user), -$amount, $type, $idempotencyKey, $by, $referenceType, $referenceId, $note);
    }

    /**
     * Undoes the row written with $originalKey by appending a REVERSAL of the opposite amount. Once: a second call
     * (any key) returns the existing reversal. Null when there is no such row (nothing was ever applied).
     */
    public function reverse(string $originalKey, string $idempotencyKey, WalletActor $by, ?string $note = null): ?WalletTransaction
    {
        $this->assertKey($idempotencyKey);
        $original = $this->findTransaction($originalKey);
        if (!$original instanceof WalletTransaction) {
            return null;
        }
        if (WalletTransactionType::REVERSAL === $original->getType()) {
            throw new \InvalidArgumentException('A reversal cannot be reversed; write an adjustment instead.');
        }

        return $this->write($this->userId($original->getWallet()->getUser()), -$original->getAmount(), WalletTransactionType::REVERSAL, $idempotencyKey, $by, $original->getReferenceType(), $original->getReferenceId(), $note, $original);
    }

    /**
     * @throws WalletException credit_limit_below_usage when the balance already uses more credit than the new limit
     */
    public function setCreditLimit(User $user, int $creditLimit, WalletActor $by): Wallet
    {
        if ($creditLimit < 0 || $creditLimit > self::MAX_AMOUNT) {
            throw new \InvalidArgumentException('The credit limit must be between 0 and '.self::MAX_AMOUNT.'.');
        }

        return $this->changeWallet($this->userId($user), function (Wallet $wallet) use ($creditLimit): void {
            if ($wallet->getBalance() + $creditLimit < 0) {
                throw new WalletException('credit_limit_below_usage', sprintf('Balance %d already uses more than a credit limit of %d.', $wallet->getBalance(), $creditLimit));
            }
            $wallet->changeCreditLimit($creditLimit);
        }, ['action' => 'credit_limit', 'value' => $creditLimit, 'source' => $by->source, 'admin_id' => $by->adminId]);
    }

    public function setStatus(User $user, string $status, WalletActor $by): Wallet
    {
        if (!in_array($status, WalletStatus::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown wallet status "%s".', $status));
        }

        return $this->changeWallet($this->userId($user), static fn (Wallet $wallet) => $wallet->changeStatus($status), ['action' => 'status', 'value' => $status, 'source' => $by->source, 'admin_id' => $by->adminId]);
    }

    private function write(int $userId, int $signedAmount, string $type, string $key, WalletActor $by, ?string $referenceType, ?string $referenceId, ?string $note, ?WalletTransaction $reversalOf = null): WalletTransaction
    {
        $connection = $this->entityManager->getConnection();
        $nested = $connection->isTransactionActive();
        $this->entityManager->flush();
        $connection->beginTransaction();
        $mutated = false;

        try {
            $wallet = $this->lock($userId);

            // Read after the lock: a writer with this key on this wallet has committed by now.
            $existing = $this->findTransaction($key);
            if ($existing instanceof WalletTransaction) {
                if ($existing->getWallet()->getId() !== $wallet->getId() || $existing->getType() !== $type || $existing->getAmount() !== $signedAmount || $existing->getReversalOf()?->getId() !== $reversalOf?->getId()) {
                    throw new WalletIdempotencyConflict($key);
                }
                $connection->commit();

                return $existing;
            }
            if ($reversalOf instanceof WalletTransaction) {
                $previousReversal = $this->entityManager->getRepository(WalletTransaction::class)->findOneBy(['reversalOf' => $reversalOf]);
                if ($previousReversal instanceof WalletTransaction) {
                    $connection->commit();

                    return $previousReversal;
                }
            }

            if (WalletTransactionType::PURCHASE === $type && $wallet->isFrozen()) {
                throw new WalletFrozen();
            }
            $balanceAfter = $wallet->getBalance() + $signedAmount;
            if ($signedAmount < 0 && $balanceAfter + $wallet->getCreditLimit() < 0) {
                throw new InsufficientWalletBalance($wallet->getBalance(), $wallet->getCreditLimit(), -$signedAmount);
            }

            $mutated = true;
            $transaction = new WalletTransaction(
                $wallet,
                $type,
                $signedAmount,
                $balanceAfter,
                $key,
                $by->source,
                $by->adminId,
                $by->label,
                $referenceType,
                null === $referenceId ? null : mb_substr($referenceId, 0, 64),
                $note,
                $reversalOf,
            );
            $wallet->applyMovement($signedAmount, $transaction->getCreatedAt());
            $this->entityManager->persist($transaction);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $this->rollback($nested, $mutated, $e);

            throw $e;
        }

        $this->logger->info('Wallet movement written.', ['wallet_id' => $wallet->getId(), 'transaction_id' => $transaction->getId(), 'type' => $type, 'amount' => $signedAmount, 'balance_after' => $balanceAfter, 'source' => $by->source]);

        return $transaction;
    }

    /**
     * @param callable(Wallet): void $change
     * @param array<string, mixed>   $logContext
     */
    private function changeWallet(int $userId, callable $change, array $logContext): Wallet
    {
        $connection = $this->entityManager->getConnection();
        $nested = $connection->isTransactionActive();
        $this->entityManager->flush();
        $connection->beginTransaction();

        try {
            $wallet = $this->lock($userId);
            $change($wallet);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            // A refusal (WalletException) is thrown before anything changes.
            $this->rollback($nested, !$e instanceof WalletException, $e);

            throw $e;
        }
        $this->logger->info('Wallet changed.', ['wallet_id' => $wallet->getId()] + $logContext);

        return $wallet;
    }

    /**
     * Locks the wallet row (creating it on first use) and re-reads the entity under that lock.
     *
     * The user row is the mutex: SELECT ... FOR UPDATE on an existing primary key locks that one row and no gap, so
     * creating a missing wallet cannot deadlock with another user's first wallet, and two writers of one wallet queue
     * here. The wallet row is then locked as well; refresh() with a lock mode is a locking read, so it sees the
     * latest committed balance, never a stale snapshot or identity-map copy.
     */
    private function lock(int $userId): Wallet
    {
        $connection = $this->entityManager->getConnection();
        if (false === $connection->fetchOne('SELECT id FROM `user` WHERE id = ? FOR UPDATE', [$userId])) {
            throw new \RuntimeException(sprintf('User #%d not found.', $userId));
        }
        if (false === $connection->fetchOne('SELECT id FROM wallet WHERE user_id = ?', [$userId])) {
            // IGNORE: the plain read above may be an old snapshot of a wallet created meanwhile (we hold the user lock).
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $connection->executeStatement(
                'INSERT IGNORE INTO wallet (user_id, balance, credit_limit, currency, status, created_at, updated_at) VALUES (?, 0, 0, ?, ?, ?, ?)',
                [$userId, Wallet::CURRENCY, WalletStatus::ACTIVE, $now, $now]
            );
        }
        $walletId = $connection->fetchOne('SELECT id FROM wallet WHERE user_id = ? FOR UPDATE', [$userId]);
        if (false === $walletId) {
            throw new \RuntimeException(sprintf('Wallet for user #%d not found.', $userId));
        }
        $wallet = $this->entityManager->find(Wallet::class, (int) $walletId) ?? throw new \RuntimeException(sprintf('Wallet #%d not found.', (int) $walletId));
        $this->entityManager->refresh($wallet, LockMode::PESSIMISTIC_WRITE);

        return $wallet;
    }

    /**
     * A refused write changed nothing in memory and only its savepoint/transaction is rolled back. A write that failed
     * half-way leaves the unit of work dirty: as top-level transaction it is discarded here (cleared, or rebuilt when
     * the failure closed the entity manager); nested, the caller's own rollback handles it (as settlement does).
     */
    private function rollback(bool $nested, bool $mutated, \Throwable $error): void
    {
        $connection = $this->entityManager->getConnection();
        try {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Wallet rollback failed.', ['error' => $e->getMessage()]);
        }

        if (!$mutated || $nested) {
            return;
        }
        $this->logger->error('Wallet write failed; rolled back.', ['error' => $error->getMessage()]);
        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();
        } else {
            $this->managerRegistry->resetManager();
        }
    }

    private function assertMovement(int $amount, string $type, string $key): void
    {
        if ($amount <= 0 || $amount > self::MAX_AMOUNT) {
            throw new \InvalidArgumentException(sprintf('A wallet amount must be a positive integer up to %d; %d given.', self::MAX_AMOUNT, $amount));
        }
        if (!in_array($type, WalletTransactionType::DIRECT, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a credit/debit type.', $type));
        }
        $this->assertKey($key);
    }

    private function assertKey(string $key): void
    {
        if (1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException('An idempotency key is 1-128 characters of A-Z a-z 0-9 : . _ -.');
        }
    }

    private function userId(User $user): int
    {
        return $user->getId() ?? throw new \InvalidArgumentException('The user is not saved yet.');
    }
}
