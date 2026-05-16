<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515191600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize bot content datetime columns';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('bot_message_template')) {
            $this->addSql('ALTER TABLE bot_message_template CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        }
        if ($schema->hasTable('bot_button_label')) {
            $this->addSql('ALTER TABLE bot_button_label CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('bot_message_template')) {
            $this->addSql('ALTER TABLE bot_message_template CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
        if ($schema->hasTable('bot_button_label')) {
            $this->addSql('ALTER TABLE bot_button_label CHANGE created_at created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE updated_at updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }
}
