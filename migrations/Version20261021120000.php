<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * admin_user.telegram_user_id: links an admin to their Telegram account, so the bot applies the same permissions
 * (AdminAccessPolicy). Nullable: existing admins stay unlinked and the TELEGRAM_ADMIN_CHAT_ID fallback keeps working.
 */
final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'admin_user: telegram_user_id (nullable, unique) to link admins to the bot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_user ADD telegram_user_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_admin_user_telegram_user_id ON admin_user (telegram_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_admin_user_telegram_user_id ON admin_user');
        $this->addSql('ALTER TABLE admin_user DROP telegram_user_id');
    }
}
