<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515191200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add bot message templates and button labels';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('bot_message_template')) {
            $this->addSql('CREATE TABLE bot_message_template (id INT AUTO_INCREMENT NOT NULL, template_key VARCHAR(190) NOT NULL, locale VARCHAR(10) NOT NULL, title VARCHAR(190) DEFAULT NULL, body LONGTEXT NOT NULL, parse_mode VARCHAR(20) NOT NULL, variables JSON DEFAULT NULL, category VARCHAR(80) DEFAULT NULL, is_active TINYINT(1) NOT NULL, is_system TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_bot_message_template_key_locale (template_key, locale), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('bot_button_label')) {
            $this->addSql('CREATE TABLE bot_button_label (id INT AUTO_INCREMENT NOT NULL, label_key VARCHAR(190) NOT NULL, locale VARCHAR(10) NOT NULL, label VARCHAR(190) NOT NULL, button_type VARCHAR(40) DEFAULT NULL, category VARCHAR(80) DEFAULT NULL, is_active TINYINT(1) NOT NULL, is_system TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_bot_button_label_key_locale (label_key, locale), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('bot_button_label')) {
            $this->addSql('DROP TABLE bot_button_label');
        }
        if ($schema->hasTable('bot_message_template')) {
            $this->addSql('DROP TABLE bot_message_template');
        }
    }
}
