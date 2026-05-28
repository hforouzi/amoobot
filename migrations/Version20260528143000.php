<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add trial plans and trial claim tracking';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('trial_plan')) {
            $this->addSql('CREATE TABLE trial_plan (id INT AUTO_INCREMENT NOT NULL, inbound_id INT DEFAULT NULL, backing_plan_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_active TINYINT(1) NOT NULL, sort_order INT NOT NULL, duration_days INT NOT NULL, traffic_gb INT DEFAULT NULL, ip_limit INT DEFAULT NULL, max_claims_total INT DEFAULT NULL, max_claims_per_user INT NOT NULL, cooldown_hours INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_D8F0C577A70793B2 (inbound_id), INDEX IDX_D8F0C577111C5A59 (backing_plan_id), INDEX idx_trial_plan_active_sort (is_active, sort_order, id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE trial_plan ADD CONSTRAINT FK_D8F0C577A70793B2 FOREIGN KEY (inbound_id) REFERENCES vpn_inbound (id) ON DELETE SET NULL');
            $this->addSql('ALTER TABLE trial_plan ADD CONSTRAINT FK_D8F0C577111C5A59 FOREIGN KEY (backing_plan_id) REFERENCES plan (id) ON DELETE SET NULL');
        }

        if (!$schema->hasTable('trial_claim')) {
            $this->addSql('CREATE TABLE trial_claim (id INT AUTO_INCREMENT NOT NULL, telegram_account_id INT NOT NULL, trial_plan_id INT NOT NULL, order_id INT DEFAULT NULL, vpn_service_id INT DEFAULT NULL, status VARCHAR(50) NOT NULL, failure_reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', provisioned_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_6DF265C29F6A7D7 (telegram_account_id), INDEX IDX_6DF265CECE3F6BD (trial_plan_id), INDEX IDX_6DF265C8D9F6D38 (order_id), INDEX IDX_6DF265C416A20B (vpn_service_id), INDEX idx_trial_claim_account_plan_status (telegram_account_id, trial_plan_id, status), INDEX idx_trial_claim_plan_status (trial_plan_id, status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE trial_claim ADD CONSTRAINT FK_6DF265C29F6A7D7 FOREIGN KEY (telegram_account_id) REFERENCES telegram_account (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE trial_claim ADD CONSTRAINT FK_6DF265CECE3F6BD FOREIGN KEY (trial_plan_id) REFERENCES trial_plan (id) ON DELETE CASCADE');
            $this->addSql('ALTER TABLE trial_claim ADD CONSTRAINT FK_6DF265C8D9F6D38 FOREIGN KEY (order_id) REFERENCES `order` (id) ON DELETE SET NULL');
            $this->addSql('ALTER TABLE trial_claim ADD CONSTRAINT FK_6DF265C416A20B FOREIGN KEY (vpn_service_id) REFERENCES vpn_service (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('trial_claim')) {
            $this->addSql('ALTER TABLE trial_claim DROP FOREIGN KEY FK_6DF265C29F6A7D7');
            $this->addSql('ALTER TABLE trial_claim DROP FOREIGN KEY FK_6DF265CECE3F6BD');
            $this->addSql('ALTER TABLE trial_claim DROP FOREIGN KEY FK_6DF265C8D9F6D38');
            $this->addSql('ALTER TABLE trial_claim DROP FOREIGN KEY FK_6DF265C416A20B');
            $this->addSql('DROP TABLE trial_claim');
        }

        if ($schema->hasTable('trial_plan')) {
            $this->addSql('ALTER TABLE trial_plan DROP FOREIGN KEY FK_D8F0C577A70793B2');
            $this->addSql('ALTER TABLE trial_plan DROP FOREIGN KEY FK_D8F0C577111C5A59');
            $this->addSql('DROP TABLE trial_plan');
        }
    }
}
