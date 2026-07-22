<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260605090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create broadcast message and delivery tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE broadcast_message (id INT AUTO_INCREMENT NOT NULL, created_by_id INT DEFAULT NULL, title VARCHAR(255) NOT NULL, body LONGTEXT NOT NULL, status VARCHAR(32) NOT NULL, target_type VARCHAR(64) NOT NULL, target_user_ids JSON DEFAULT NULL, parse_mode VARCHAR(20) DEFAULT NULL, disable_web_page_preview TINYINT(1) NOT NULL, send_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", started_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", finished_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", total_recipients INT NOT NULL, sent_count INT NOT NULL, failed_count INT NOT NULL, skipped_count INT NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", metadata JSON DEFAULT NULL, INDEX IDX_5DA4569BDE12AB56 (created_by_id), INDEX idx_broadcast_message_status_send_at (status, send_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE broadcast_delivery (id INT AUTO_INCREMENT NOT NULL, broadcast_id INT NOT NULL, telegram_account_id INT DEFAULT NULL, user_id INT DEFAULT NULL, chat_id VARCHAR(255) NOT NULL, status VARCHAR(32) NOT NULL, telegram_message_id VARCHAR(64) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, sent_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_F8C35223BROADCAST (broadcast_id), INDEX IDX_F8C35223TELEGRAM (telegram_account_id), INDEX IDX_F8C35223USER (user_id), INDEX idx_broadcast_delivery_status (status), UNIQUE INDEX uniq_broadcast_delivery_chat (broadcast_id, chat_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE broadcast_message ADD CONSTRAINT FK_5DA4569BDE12AB56 FOREIGN KEY (created_by_id) REFERENCES admin_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE broadcast_delivery ADD CONSTRAINT FK_F8C35223BROADCAST FOREIGN KEY (broadcast_id) REFERENCES broadcast_message (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE broadcast_delivery ADD CONSTRAINT FK_F8C35223TELEGRAM FOREIGN KEY (telegram_account_id) REFERENCES telegram_account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE broadcast_delivery ADD CONSTRAINT FK_F8C35223USER FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');

        if ($schema->hasTable('setting')) {
            $this->addSql(
                'INSERT INTO setting (key_name, value, type, created_at, updated_at)
                 SELECT ?, ?, ?, NOW(), NULL
                 WHERE NOT EXISTS (SELECT 1 FROM setting WHERE key_name = ?)',
                ['automation.broadcasts_enabled', 'true', 'boolean', 'automation.broadcasts_enabled']
            );
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('broadcast_delivery')) {
            $this->addSql('ALTER TABLE broadcast_delivery DROP FOREIGN KEY FK_F8C35223BROADCAST');
            $this->addSql('ALTER TABLE broadcast_delivery DROP FOREIGN KEY FK_F8C35223TELEGRAM');
            $this->addSql('ALTER TABLE broadcast_delivery DROP FOREIGN KEY FK_F8C35223USER');
            $this->addSql('DROP TABLE broadcast_delivery');
        }

        if ($schema->hasTable('broadcast_message')) {
            $this->addSql('ALTER TABLE broadcast_message DROP FOREIGN KEY FK_5DA4569BDE12AB56');
            $this->addSql('DROP TABLE broadcast_message');
        }

        if ($schema->hasTable('setting')) {
            $this->addSql('DELETE FROM setting WHERE key_name = ?', ['automation.broadcasts_enabled']);
        }
    }
}
