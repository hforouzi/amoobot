<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505073000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Phase 1 MVP tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) DEFAULT NULL, mobile VARCHAR(50) DEFAULT NULL, status VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE telegram_account (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, telegram_id VARCHAR(255) NOT NULL, username VARCHAR(255) DEFAULT NULL, first_name VARCHAR(255) DEFAULT NULL, last_name VARCHAR(255) DEFAULT NULL, language_code VARCHAR(32) DEFAULT NULL, last_activity_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", UNIQUE INDEX UNIQ_8E9B6D4A64D218E (user_id), UNIQUE INDEX UNIQ_8E9B6D45A973A0A (telegram_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE plan (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, duration_days INT NOT NULL, traffic_gb INT DEFAULT NULL, price INT NOT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE `order` (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, plan_id INT NOT NULL, amount INT NOT NULL, status VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", paid_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", provisioned_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_F5299398A76ED395 (user_id), INDEX IDX_F5299398C1821EFA (plan_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, method VARCHAR(64) NOT NULL, amount INT NOT NULL, status VARCHAR(50) NOT NULL, tracking_code VARCHAR(255) DEFAULT NULL, receipt_file_id VARCHAR(255) DEFAULT NULL, receipt_message LONGTEXT DEFAULT NULL, admin_note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", submitted_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", confirmed_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_6D28840D8D9F6D38 (order_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE vpn_panel (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(64) NOT NULL, title VARCHAR(255) NOT NULL, base_url VARCHAR(255) DEFAULT NULL, username VARCHAR(255) DEFAULT NULL, password VARCHAR(255) DEFAULT NULL, api_token LONGTEXT DEFAULT NULL, config JSON DEFAULT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE vpn_service (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, order_id INT DEFAULT NULL, panel_id INT DEFAULT NULL, remote_id VARCHAR(255) DEFAULT NULL, username VARCHAR(255) DEFAULT NULL, subscription_url LONGTEXT DEFAULT NULL, config_text LONGTEXT DEFAULT NULL, status VARCHAR(50) NOT NULL, starts_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", expires_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", traffic_limit_gb INT DEFAULT NULL, traffic_used_gb INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_6A0B90E9A76ED395 (user_id), INDEX IDX_6A0B90E98D9F6D38 (order_id), INDEX IDX_6A0B90E99EB6921 (panel_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE bot_message_log (id INT AUTO_INCREMENT NOT NULL, telegram_id VARCHAR(255) DEFAULT NULL, direction VARCHAR(20) NOT NULL, update_type VARCHAR(64) DEFAULT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE setting (id INT AUTO_INCREMENT NOT NULL, key_name VARCHAR(190) NOT NULL, value LONGTEXT DEFAULT NULL, type VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", UNIQUE INDEX uniq_setting_key_name (key_name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE telegram_account ADD CONSTRAINT FK_8E9B6D4A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F5299398A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F5299398C1821EFA FOREIGN KEY (plan_id) REFERENCES plan (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D8D9F6D38 FOREIGN KEY (order_id) REFERENCES `order` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vpn_service ADD CONSTRAINT FK_6A0B90E9A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vpn_service ADD CONSTRAINT FK_6A0B90E98D9F6D38 FOREIGN KEY (order_id) REFERENCES `order` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vpn_service ADD CONSTRAINT FK_6A0B90E99EB6921 FOREIGN KEY (panel_id) REFERENCES vpn_panel (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE telegram_account DROP FOREIGN KEY FK_8E9B6D4A76ED395');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F5299398A76ED395');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F5299398C1821EFA');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D8D9F6D38');
        $this->addSql('ALTER TABLE vpn_service DROP FOREIGN KEY FK_6A0B90E9A76ED395');
        $this->addSql('ALTER TABLE vpn_service DROP FOREIGN KEY FK_6A0B90E98D9F6D38');
        $this->addSql('ALTER TABLE vpn_service DROP FOREIGN KEY FK_6A0B90E99EB6921');

        $this->addSql('DROP TABLE bot_message_log');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE telegram_account');
        $this->addSql('DROP TABLE vpn_service');
        $this->addSql('DROP TABLE `order`');
        $this->addSql('DROP TABLE plan');
        $this->addSql('DROP TABLE vpn_panel');
        $this->addSql('DROP TABLE `user`');
    }
}
