<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\Order;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Migration Version20261024090100 (order.plan_id nullable): its statements run online on this server (MariaDB/MySQL:
 * ALGORITHM=INPLACE, LOCK=NONE) and it is reversible only while no wallet top-up order exists; with one, down()
 * refuses and changes nothing.
 */
final class WalletPlanMigrationTest extends IntegrationTestCase
{
    use ConsoleOutputAssertions;

    private const VERSION = 'DoctrineMigrations\\Version20261024090100';

    private function planIdNullable(): bool
    {
        return 'YES' === $this->em->getConnection()->fetchOne("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order' AND COLUMN_NAME = 'plan_id'");
    }

    /** Runs the migration in its own console process (Doctrine Migrations freezes its configuration after one run). */
    private function migrate(string $direction): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'bin/console', 'doctrine:migrations:execute', self::VERSION, '--'.$direction, '--no-interaction', '--env=test'], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 120);
        $process->run();

        return $process;
    }

    public function testDownIsRefusedWhileATopUpOrderExistsAndNothingChanges(): void
    {
        $order = (new Order())->setUser($this->createUser())->setType(OrderType::WALLET_TOPUP)->setAmount(50000)->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->persist($order);
        $this->em->flush();

        try {
            $tester = $this->migrate('down');

            self::assertConsoleContains('Irreversible: 1 order(s) have no plan', $tester->getOutput().$tester->getErrorOutput());
            self::assertTrue($this->planIdNullable(), 'nothing was changed');
            self::assertNull($this->row('`order`', (int) $order->getId())['plan_id']);
        } finally {
            $this->restore();
        }
    }

    public function testWithoutTopUpOrdersItGoesDownAndUpOnline(): void
    {
        try {
            $down = $this->migrate('down');
            self::assertFalse($this->planIdNullable(), 'down: NOT NULL again. '.$down->getOutput().$down->getErrorOutput());

            $up = $this->migrate('up');
            self::assertTrue($this->planIdNullable(), 'up: nullable. '.$up->getOutput().$up->getErrorOutput());
        } finally {
            $this->restore();
        }
    }

    /** Whatever happened above, leave the schema and the migration table as the rest of the suite expects. */
    private function restore(): void
    {
        $connection = $this->em->getConnection();
        if (!$this->planIdNullable()) {
            $connection->executeStatement('ALTER TABLE `order` MODIFY plan_id INT DEFAULT NULL');
        }
        if (false === $connection->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [self::VERSION])) {
            $connection->insert('doctrine_migration_versions', ['version' => self::VERSION, 'executed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'execution_time' => 0]);
        }
    }
}
