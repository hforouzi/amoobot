<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * mini_app_session: Telegram Mini App logins (only the SHA-256 of the bearer token is stored).
 */
final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'mini_app_session for the Telegram Mini App';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mini_app_session (id INT AUTO_INCREMENT NOT NULL, token_hash CHAR(64) NOT NULL, telegram_user_id VARCHAR(32) NOT NULL, user_agent_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, last_seen_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, INDEX idx_mini_app_session_telegram_user (telegram_user_id), UNIQUE INDEX uniq_mini_app_session_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mini_app_session');
    }
}
