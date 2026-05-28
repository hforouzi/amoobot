<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add required Telegram channels membership gate';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('required_channel')) {
            return;
        }

        $this->addSql('CREATE TABLE required_channel (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, chat_id VARCHAR(255) NOT NULL, invite_url VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) NOT NULL, require_for_purchase TINYINT(1) NOT NULL, require_for_trial TINYINT(1) NOT NULL, sort_order INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_required_channel_active_purchase (is_active, require_for_purchase, sort_order), INDEX idx_required_channel_active_trial (is_active, require_for_trial, sort_order), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('required_channel')) {
            return;
        }

        $this->addSql('DROP TABLE required_channel');
    }
}
