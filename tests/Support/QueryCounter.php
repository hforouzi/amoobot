<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Test-only DBAL middleware: counts the SQL statements sent to the database, for query-budget tests
 * (DashboardOverview must not grow a query per row). The state is static: DoctrineBundle builds its own copy of a
 * middleware service for each connection, so the instance a test gets is not the one wrapping the driver.
 */
final class QueryCounter implements Middleware
{
    private static int $count = 0;
    private static bool $counting = false;

    public function wrap(Driver $driver): Driver
    {
        $counter = $this;

        return new class($driver, $counter) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly QueryCounter $counter)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $counter = $this->counter;

                return new class(parent::connect($params), $counter) extends AbstractConnectionMiddleware {
                    public function __construct(Connection $connection, private readonly QueryCounter $counter)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $counter = $this->counter;

                        return new class(parent::prepare($sql), $counter) extends AbstractStatementMiddleware {
                            public function __construct(Statement $statement, private readonly QueryCounter $counter)
                            {
                                parent::__construct($statement);
                            }

                            public function execute(): Result
                            {
                                $this->counter->count();

                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $this->counter->count();

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->counter->count();

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public function start(): void
    {
        self::$count = 0;
        self::$counting = true;
    }

    public function stop(): int
    {
        self::$counting = false;

        return self::$count;
    }

    public function count(): void
    {
        if (self::$counting) {
            ++self::$count;
        }
    }
}
