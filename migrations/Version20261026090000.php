<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * payment.stuck_reminded_at: when the admins were last reminded that a payment is stuck (provisioning_failed, or in
 * review with a held wallet debit) for more than 6 hours (StuckPaymentReminder, at most once a day per payment). A
 * nullable column without a default: existing rows get NULL and nothing is rewritten (an instant ADD COLUMN on
 * MariaDB 10.3+ / MySQL 8.0.12+).
 */
final class Version20261026090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'payment.stuck_reminded_at (daily admin reminder for a stuck payment)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment ADD stuck_reminded_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP stuck_reminded_at');
    }
}
