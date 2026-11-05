<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one lock of a pass over inbound moves (docs/design/plan-inbound-change.md 5.2, PR P8): the scheduled task
 * (InboundMoveScheduler) and the foreground app:plan:apply-inbounds take the same name, and neither waits: the CLI
 * refuses, the task skips.
 *
 * It is a MariaDB named lock (GET_LOCK with timeout 0) on a connection of its own:
 * - symfony/lock is not a dependency, and a lock file would have to be shared by the www-data jobs and an admin's shell;
 * - the server releases it the moment the connection ends, so a killed process (kill -9) leaves no lock behind;
 * - it is not tied to the application connection, which TransactionRollback may close after a failure, and two
 *   acquisitions in one process conflict as they should (a named lock is re-entrant on one connection);
 * - the name holds a hash of the database name, because a named lock is server-wide.
 *
 * This lock is only the fast way out: the claims of the moves (ServiceInboundAttacher::STALE_CLAIM) stay what guarantees
 * that one item never has two runners. --pause-run and --cancel never take it: they must work during a pass.
 */
final class InboundMoveLock
{
    public const NAME = 'amoobot_inbound_moves';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** The lock, or null at once when another pass holds it. Release it when the pass ends. */
    public function acquire(): ?InboundMoveHeldLock
    {
        $application = $this->entityManager->getConnection();
        $connection = DriverManager::getConnection($application->getParams());
        try {
            $name = self::NAME.'_'.substr(sha1((string) $connection->fetchOne('SELECT DATABASE()')), 0, 16);
            if (1 === (int) $connection->fetchOne('SELECT GET_LOCK(?, 0)', [$name])) {
                return new InboundMoveHeldLock($connection);
            }
        } catch (\Throwable $e) {
            $connection->close();

            throw $e;
        }
        $connection->close();

        return null;
    }
}
