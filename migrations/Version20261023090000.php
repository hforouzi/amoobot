<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * checkout_request: Mini App checkout calls by Idempotency-Key (one order/payment per key).
 */
final class Version20261023090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'checkout_request for Mini App checkout idempotency';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE checkout_request (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, idempotency_key VARCHAR(64) NOT NULL, scope VARCHAR(64) NOT NULL, result JSON DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_C7FC6BE6A76ED395 (user_id), UNIQUE INDEX uniq_checkout_request_user_key (user_id, idempotency_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE checkout_request ADD CONSTRAINT FK_CHECKOUT_REQUEST_USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE checkout_request');
    }
}
