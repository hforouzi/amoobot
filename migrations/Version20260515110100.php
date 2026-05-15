<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515110100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add plugin registry table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plugin (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(128) NOT NULL, type VARCHAR(64) NOT NULL, name JSON NOT NULL, version VARCHAR(64) NOT NULL, description JSON DEFAULT NULL, status VARCHAR(32) NOT NULL, path VARCHAR(512) NOT NULL, main_class VARCHAR(255) DEFAULT NULL, manifest JSON NOT NULL, permissions JSON DEFAULT NULL, installed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', enabled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', disabled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_plugin_code (code), INDEX IDX_PLUGIN_STATUS (status), INDEX IDX_PLUGIN_TYPE (type), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE plugin');
    }
}
