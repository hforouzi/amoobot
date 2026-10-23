<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Rolling back after a failure without leaving a broken connection behind (PaymentSettlementService, WalletPayment).
 *
 * When the rollback itself fails (e.g. the database already rolled the whole transaction back after a deadlock, so a
 * savepoint of an inner level no longer exists), the connection is CLOSED: the server drops whatever is left of the
 * transaction and DBAL's nesting level starts again at 0; the next query reconnects. Without that, DBAL still thought
 * it was inside a transaction the server no longer had: in a long-running process (the bot worker) every later
 * "transaction" ran as a savepoint of nothing and failed ("SAVEPOINT DOCTRINE_2 does not exist").
 */
final class TransactionRollback
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Rolls back every transaction level above $toLevel (0: all of them).
     *
     * @return bool false when the rollback failed and the connection was closed
     */
    public function rollBack(int $toLevel = 0): bool
    {
        $connection = $this->entityManager->getConnection();
        try {
            while ($connection->getTransactionNestingLevel() > $toLevel) {
                $connection->rollBack();
            }
        } catch (\Throwable $e) {
            $this->logger->critical('Rollback failed; the database connection was closed so no transaction state is lost.', ['error' => $e->getMessage()]);
            $connection->close();

            return false;
        }

        return true;
    }

    /** Discards the unit of work: cleared, or rebuilt when the failure closed the entity manager. */
    public function resetEntityManager(): void
    {
        if ($this->entityManager->isOpen()) {
            $this->entityManager->clear();
        } else {
            $this->managerRegistry->resetManager();
        }
    }
}
