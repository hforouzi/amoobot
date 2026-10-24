<?php

declare(strict_types=1);

namespace App\Wallet\Application;

use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletReferenceType;
use App\Wallet\Domain\WalletStatus;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Admin changes to a wallet (WALLETS_MANAGE), shared by the web panel and the Mini App admin: a manual credit/debit
 * (ADJUSTMENT) with a mandatory reason, the credit limit, freeze/unfreeze. Every write goes through WalletService and
 * is audited with its source (web / bot / miniapp). The caller checks the permission and the confirmation.
 *
 * An adjustment carries a key made when its form was shown, so submitting the same form twice applies it once; its
 * audit entry is written in the same transaction (both or neither, once).
 *
 * Each write is retried as a whole when the database picks it as a deadlock victim (e.g. a freeze racing a purchase
 * or a settlement on the same wallet): it was rolled back completely, so running it again is safe and changes nothing
 * twice (the adjustment keeps its key). After DEADLOCK_ATTEMPTS it is refused with WalletException "busy" (nothing
 * changed; the admin tries again). Never inside a caller's transaction: that one is lost with the deadlock.
 */
final class WalletAdministration
{
    public const DIRECTION_CREDIT = 'credit';
    public const DIRECTION_DEBIT = 'debit';
    // /D: a form key with a trailing newline would be another adjustment key (applied twice).
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/D';

    public const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly AdminAuditLogger $auditLogger,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** A fresh key for an adjustment form. */
    public static function newFormKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Why this adjustment cannot be made (a message key of the admin domain), or null when it can.
     */
    public function adjustmentProblem(string $direction, mixed $amount, ?string $reason, ?string $formKey): ?string
    {
        return match (true) {
            !in_array($direction, [self::DIRECTION_CREDIT, self::DIRECTION_DEBIT], true) => 'wallet_admin.error.direction',
            null === self::amount($amount) => 'wallet_admin.error.amount',
            mb_strlen(trim((string) $reason)) < 3 || mb_strlen(trim((string) $reason)) > 500 => 'wallet_admin.error.reason',
            1 !== preg_match(self::KEY_PATTERN, (string) $formKey) => 'wallet_admin.error.form_expired',
            default => null,
        };
    }

    /**
     * @throws \InvalidArgumentException when adjustmentProblem() is not null
     * @throws WalletException           wallet_insufficient (a debit beyond balance + credit limit)
     */
    public function adjust(User $customer, string $direction, mixed $amount, string $reason, string $formKey, AdminActor $admin): WalletTransaction
    {
        if (null !== ($problem = $this->adjustmentProblem($direction, $amount, $reason, $formKey))) {
            throw new \InvalidArgumentException($problem);
        }
        $amount = (int) self::amount($amount);
        $reason = trim($reason);
        $key = sprintf('adjust:%d:%s', (int) $customer->getId(), $formKey);
        $by = WalletActor::admin($admin);
        // The audit entry is written inside the adjustment's own transaction, and only when the row is new: both exist
        // or neither does, and a repeated (or simultaneous) submit gets the first row back without a second entry.
        $audit = fn (WalletTransaction $transaction) => $this->auditLogger->recordOrThrow('wallet.adjust', 'Wallet', $transaction->getWallet()->getId(), [
            'customer_id' => $customer->getId(),
            'direction' => $direction,
            'amount' => $amount,
            'reason' => $reason,
            'transaction_id' => $transaction->getId(),
            'balance_after' => $transaction->getBalanceAfter(),
        ], $admin);
        $adminId = null === $admin->adminUserId ? null : (string) $admin->adminUserId;

        return $this->retryOnDeadlock('adjust', fn (): WalletTransaction => self::DIRECTION_CREDIT === $direction
            ? $this->wallets->credit($customer, $amount, WalletTransactionType::ADJUSTMENT, $key, $by, WalletReferenceType::ADMIN, $adminId, $reason, $audit)
            : $this->wallets->debit($customer, $amount, WalletTransactionType::ADJUSTMENT, $key, $by, WalletReferenceType::ADMIN, $adminId, $reason, $audit));
    }

    /**
     * @throws WalletException credit_limit_below_usage, busy
     */
    public function setCreditLimit(User $customer, mixed $creditLimit, AdminActor $admin): Wallet
    {
        $limit = '0' === trim(is_scalar($creditLimit) ? (string) $creditLimit : '') ? 0 : self::amount($creditLimit);
        if (null === $limit) {
            throw new \InvalidArgumentException('wallet_admin.error.amount');
        }
        $before = $this->wallets->find($customer)?->getCreditLimit() ?? 0;
        $wallet = $this->retryOnDeadlock('credit_limit', fn (): Wallet => $this->wallets->setCreditLimit($customer, $limit, WalletActor::admin($admin)));
        $this->auditLogger->record('wallet.credit_limit', 'Wallet', $wallet->getId(), ['customer_id' => $customer->getId(), 'from' => $before, 'to' => $limit], $admin);

        return $wallet;
    }

    /**
     * @throws WalletException busy
     */
    public function setFrozen(User $customer, bool $frozen, AdminActor $admin): Wallet
    {
        $wallet = $this->retryOnDeadlock($frozen ? 'freeze' : 'unfreeze', fn (): Wallet => $this->wallets->setStatus($customer, $frozen ? WalletStatus::FROZEN : WalletStatus::ACTIVE, WalletActor::admin($admin)));
        $this->auditLogger->record($frozen ? 'wallet.freeze' : 'wallet.unfreeze', 'Wallet', $wallet->getId(), ['customer_id' => $customer->getId()], $admin);

        return $wallet;
    }

    /**
     * Runs one admin wallet write, again from the start when it was a deadlock victim (see the class comment).
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     *
     * @throws WalletException busy: still deadlocked after DEADLOCK_ATTEMPTS (nothing changed)
     */
    private function retryOnDeadlock(string $action, \Closure $write): mixed
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $write();
            } catch (DeadlockException $e) {
                $this->logger->warning('Admin wallet write was a deadlock victim.', ['action' => $action, 'attempt' => $attempt]);
                if ($attempt >= self::DEADLOCK_ATTEMPTS || $this->entityManager->getConnection()->isTransactionActive()) {
                    throw new WalletException('busy', sprintf('The wallet is busy (%s still deadlocked after %d attempt(s)); nothing was changed.', $action, $attempt));
                }
                usleep(random_int(20_000, 60_000) * $attempt);
            }
        }
    }

    /** A positive whole Toman amount within the ledger's bound, else null. */
    private static function amount(mixed $value): ?int
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? trim(str_replace([',', '٬', ' '], '', $value)) : '');
        if (1 !== preg_match('/^\d{1,13}$/D', $text)) {
            return null;
        }
        $amount = (int) $text;

        return $amount > 0 && $amount <= WalletService::MAX_AMOUNT ? $amount : null;
    }
}
