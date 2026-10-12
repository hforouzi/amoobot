<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the exact quote (amount + currency) a gateway was asked to charge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment ADD expected_amount VARCHAR(64) DEFAULT NULL, ADD expected_currency VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP expected_amount, DROP expected_currency');
    }
}
