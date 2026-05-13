<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513181000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add crypto payment fields to payment table (Phase 1.8 NOWPayments)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment
            ADD crypto_price_currency VARCHAR(32) DEFAULT NULL,
            ADD crypto_pay_currency VARCHAR(32) DEFAULT NULL,
            ADD crypto_pay_amount VARCHAR(64) DEFAULT NULL,
            ADD crypto_actually_paid VARCHAR(64) DEFAULT NULL,
            ADD crypto_outcome_amount VARCHAR(64) DEFAULT NULL,
            ADD crypto_payment_status VARCHAR(64) DEFAULT NULL,
            ADD crypto_payment_id VARCHAR(128) DEFAULT NULL,
            ADD crypto_purchase_id VARCHAR(128) DEFAULT NULL,
            ADD crypto_address TEXT DEFAULT NULL,
            ADD crypto_network VARCHAR(64) DEFAULT NULL,
            ADD crypto_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            ADD ipn_payload JSON DEFAULT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment
            DROP crypto_price_currency,
            DROP crypto_pay_currency,
            DROP crypto_pay_amount,
            DROP crypto_actually_paid,
            DROP crypto_outcome_amount,
            DROP crypto_payment_status,
            DROP crypto_payment_id,
            DROP crypto_purchase_id,
            DROP crypto_address,
            DROP crypto_network,
            DROP crypto_expires_at,
            DROP ipn_payload
        ');
    }
}
