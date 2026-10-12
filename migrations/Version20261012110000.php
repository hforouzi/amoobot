<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Settlement bookkeeping for payments.
 *
 * New status values (payment: needs_review, provisioning_failed; order: provisioning_failed) fit the
 * existing VARCHAR(50) status columns, so no column type change is needed for them.
 */
final class Version20261012110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payment settlement metadata: review reason, amount flag, provisioning failure, approver';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment
            ADD verified_amount VARCHAR(64) DEFAULT NULL,
            ADD verified_currency VARCHAR(16) DEFAULT NULL,
            ADD amount_flag VARCHAR(32) DEFAULT NULL,
            ADD review_reason LONGTEXT DEFAULT NULL,
            ADD provisioning_error LONGTEXT DEFAULT NULL,
            ADD provisioning_failed_at DATETIME DEFAULT NULL,
            ADD provisioning_attempts INT DEFAULT 0 NOT NULL,
            ADD confirmation_source VARCHAR(64) DEFAULT NULL,
            ADD confirmed_by_admin_id INT DEFAULT NULL,
            ADD confirmed_by_telegram_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_payment_status ON payment (status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_payment_status ON payment');
        $this->addSql('ALTER TABLE payment
            DROP verified_amount,
            DROP verified_currency,
            DROP amount_flag,
            DROP review_reason,
            DROP provisioning_error,
            DROP provisioning_failed_at,
            DROP provisioning_attempts,
            DROP confirmation_source,
            DROP confirmed_by_admin_id,
            DROP confirmed_by_telegram_id');
    }
}
