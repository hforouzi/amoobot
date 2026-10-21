<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * admin_audit_log: one row per admin action (web, bot, CLI), details with secrets masked (AdminAuditLogger).
 */
final class Version20261021130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'admin_audit_log: audit trail of admin actions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_audit_log (id INT AUTO_INCREMENT NOT NULL, actor_admin_user_id INT DEFAULT NULL, actor_label VARCHAR(190) NOT NULL, source VARCHAR(8) NOT NULL, action VARCHAR(64) NOT NULL, target_type VARCHAR(64) DEFAULT NULL, target_id VARCHAR(64) DEFAULT NULL, details JSON DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_admin_audit_log_created_at (created_at), INDEX idx_admin_audit_log_target (target_type, target_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_audit_log');
    }
}
