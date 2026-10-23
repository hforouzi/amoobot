<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `order`.plan_id becomes nullable: a WALLET_TOPUP order buys no plan. Every existing row keeps its plan; the
 * foreign key (RESTRICT) is unchanged.
 *
 * WARNING (production): changing a column's nullability REBUILDS the `order` table. On MySQL 5.6+ / MariaDB 10.0+
 * it runs online (ALGORITHM=INPLACE, LOCK=NONE: reads and writes continue), but it still copies the whole table:
 * run it off-peak, with free disk space for a second copy of `order`. If the server refuses INPLACE/NONE the
 * statement fails before changing anything; then run the plain "ALTER TABLE `order` MODIFY plan_id INT DEFAULT NULL"
 * in a maintenance window (it blocks writes to `order` while it copies).
 *
 * IRREVERSIBLE once a wallet top-up order exists: down() refuses while any order has no plan (those orders have no
 * plan to fall back to; delete or reassign them first, which is a business decision, never done here).
 */
final class Version20261024090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'order.plan_id nullable for wallet top-up orders (rebuilds `order`: run off-peak)';
    }

    public function up(Schema $schema): void
    {
        $online = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ', ALGORITHM=INPLACE, LOCK=NONE' : '';
        $this->addSql('ALTER TABLE `order` MODIFY plan_id INT DEFAULT NULL'.$online);
    }

    public function down(Schema $schema): void
    {
        $withoutPlan = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `order` WHERE plan_id IS NULL');
        $this->abortIf($withoutPlan > 0, sprintf('Irreversible: %d order(s) have no plan (wallet top-ups); order.plan_id cannot become NOT NULL again. Nothing was changed.', $withoutPlan));

        $online = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ', ALGORITHM=INPLACE, LOCK=NONE' : '';
        $this->addSql('ALTER TABLE `order` MODIFY plan_id INT NOT NULL'.$online);
    }
}
