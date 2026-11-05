<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use Doctrine\DBAL\Connection;

/** InboundMoveLock while it is held: closing its connection releases the lock on the server. */
final class InboundMoveHeldLock
{
    public function __construct(private ?Connection $connection)
    {
    }

    public function release(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }
}
