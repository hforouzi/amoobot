<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * payment.wallet_credit_alerted_at: when the admins were alerted that crediting a wallet top-up failed (once per
 * payment). A nullable column without a default: existing rows get NULL and nothing is rewritten (an instant ADD
 * COLUMN on MariaDB 10.3+ / MySQL 8.0.12+).
 */
final class Version20261025090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'payment.wallet_credit_alerted_at (one admin alert per failed wallet top-up credit)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment ADD wallet_credit_alerted_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP wallet_credit_alerted_at');
    }
}
