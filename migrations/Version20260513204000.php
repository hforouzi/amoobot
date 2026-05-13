<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513204000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add NOWPayments invoice fields to payment table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment ADD crypto_invoice_id VARCHAR(128) DEFAULT NULL, ADD crypto_invoice_url LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP crypto_invoice_id, DROP crypto_invoice_url');
    }
}

