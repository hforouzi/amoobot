<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511124000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add order type and target service relation for renewal flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` ADD target_service_id INT DEFAULT NULL, ADD type VARCHAR(50) NOT NULL DEFAULT \'new_service\'');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F5299398B3AB4D89 FOREIGN KEY (target_service_id) REFERENCES vpn_service (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_F5299398B3AB4D89 ON `order` (target_service_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F5299398B3AB4D89');
        $this->addSql('DROP INDEX IDX_F5299398B3AB4D89 ON `order`');
        $this->addSql('ALTER TABLE `order` DROP target_service_id, DROP type');
    }
}
