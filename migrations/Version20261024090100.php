<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `order`.plan_id becomes nullable: a WALLET_TOPUP order buys no plan. Every existing row keeps its plan; the
 * foreign key (RESTRICT) is unchanged.
 */
final class Version20261024090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'order.plan_id nullable for wallet top-up orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` MODIFY plan_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Only possible once no top-up order is left (they have no plan to fall back to).
        $this->addSql('ALTER TABLE `order` MODIFY plan_id INT NOT NULL');
    }
}
