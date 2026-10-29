<?php

declare(strict_types=1);

namespace App\Reseller\Application;

use App\Reseller\Domain\ClaimException;
use App\Shared\Infrastructure\TransactionRollback;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The one way claim-link writes run their transaction (claim, make a link, revoke, return to reseller, reset links;
 * docs/design/reseller-and-claim.md 3.4).
 *
 * Lock order, everywhere: claim rows by PRIMARY KEY (ascending id when there are several), then - read with a shared
 * lock - the order / payment rows of the service (ServiceOrdersInFlight::existsLocked), then the vpn_service row. A
 * claim row is never locked through a secondary index (open_service_id, service_id, status): InnoDB takes the index
 * entry before the row, while a claim updating that row takes the row before the index entry, and the two deadlock.
 * Rows are found by a plain read first and then locked by id and checked again.
 *
 * READ COMMITTED (only for a transaction started here, never inside a caller's): every plain read after a lock sees
 * what was committed before that lock, so "lock, then check again" is honest. Under REPEATABLE READ the check would
 * read the snapshot taken at the first read of the transaction, possibly before the lock was granted.
 *
 * A deadlock or lock wait timeout rolls everything back and runs the work again from the start (it reloads its rows by
 * id), at most ATTEMPTS times; after that it is ClaimException::BUSY ("try again in a minute"), never a raw DBAL error.
 */
class ClaimTransactions
{
    public const ATTEMPTS = 3;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionRollback $transactionRollback,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work runs inside the transaction; may throw ClaimException (rolled back, not retried)
     *
     * @return T
     *
     * @throws ClaimException
     */
    public function run(string $label, \Closure $work): mixed
    {
        for ($attempt = 1; ; ++$attempt) {
            $connection = $this->entityManager->getConnection();
            $this->entityManager->flush();
            $level = $connection->getTransactionNestingLevel();
            if (0 === $level) {
                // Applies to the next transaction only (MariaDB / MySQL), so nothing else on this connection changes.
                $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
            $connection->beginTransaction();
            try {
                $result = $work();
                $this->entityManager->flush();
                $connection->commit();

                return $result;
            } catch (\Throwable $e) {
                if ($connection->getTransactionNestingLevel() > $level) {
                    if (!$this->transactionRollback->rollBack($level) || !$this->entityManager->isOpen()) {
                        $this->transactionRollback->resetEntityManager();
                    } else {
                        // The unit of work may hold the half-made change: start clean.
                        $this->entityManager->clear();
                    }
                }
                if (!$e instanceof RetryableException) {
                    throw $e;
                }
                $this->logger->warning('claim_transaction_retry', ['action' => $label, 'attempt' => $attempt, 'error' => $e::class]);
                if (0 !== $level) {
                    // Inside a caller's transaction the whole of it is gone: the caller decides.
                    throw $e;
                }
                if ($attempt >= self::ATTEMPTS) {
                    throw new ClaimException(ClaimException::BUSY, $label.': '.$e::class);
                }
                usleep(random_int(20_000, 60_000) * $attempt);
            }
        }
    }
}
