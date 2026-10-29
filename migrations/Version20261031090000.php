<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reseller Mini App, phase S3 (docs/design/reseller-and-claim.md): a reseller's own customer labels
 * (reseller_customer: only what he typed, never a Telegram identity) and which of his sold services each one has
 * (reseller_customer_service: at most one label per service, removed with its label). Additive only: two new tables,
 * no change to any existing table or row, so up() is online-safe.
 *
 * A label never blocks deleting anything else: labels go with their reseller, a label's link goes with its label and
 * with its service (and so with a user whose services are deleted with him). Whether the reseller, service or user
 * may be deleted at all stays decided by the existing keys (orders, sold services, claims, ownership events: RESTRICT).
 *
 * down() is refused (nothing is changed) while either table has a row: dropping them would lose the resellers' labels.
 */
final class Version20261031090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'reseller Mini App (S3): reseller_customer, reseller_customer_service';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reseller_customer (id INT AUTO_INCREMENT NOT NULL, reseller_id INT NOT NULL, name VARCHAR(64) NOT NULL, phone VARCHAR(20) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_6FE5C3D91E6A19D (reseller_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reseller_customer_service (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, service_id INT NOT NULL, assigned_at DATETIME NOT NULL, INDEX IDX_CFB033099395C3F3 (customer_id), UNIQUE INDEX uniq_reseller_customer_service_service (service_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reseller_customer ADD CONSTRAINT FK_6FE5C3D91E6A19D FOREIGN KEY (reseller_id) REFERENCES reseller (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reseller_customer_service ADD CONSTRAINT FK_CFB033099395C3F3 FOREIGN KEY (customer_id) REFERENCES reseller_customer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reseller_customer_service ADD CONSTRAINT FK_CFB03309ED5CA9E6 FOREIGN KEY (service_id) REFERENCES vpn_service (id) ON DELETE CASCADE');
    }

    /**
     * Refused while either table has a row (see the class comment); checked before anything is touched.
     */
    public function down(Schema $schema): void
    {
        $labels = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reseller_customer');
        $this->abortIf($labels > 0, sprintf('Irreversible: %d reseller customer label(s) exist; dropping reseller_customer would lose them. Nothing was changed.', $labels));
        $assigned = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reseller_customer_service');
        $this->abortIf($assigned > 0, sprintf('Irreversible: %d service(s) are assigned to reseller customer labels; dropping reseller_customer_service would lose that. Nothing was changed.', $assigned));

        $this->addSql('DROP TABLE reseller_customer_service');
        $this->addSql('DROP TABLE reseller_customer');
    }
}
