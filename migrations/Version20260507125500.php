<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507125500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vpn_inbound and replace plan.panel with plan.inbound plus vpn_service.inbound';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vpn_inbound (id INT AUTO_INCREMENT NOT NULL, panel_id INT NOT NULL, remote_inbound_id VARCHAR(128) NOT NULL, title VARCHAR(255) NOT NULL, remark VARCHAR(255) DEFAULT NULL, country VARCHAR(64) DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, protocol VARCHAR(64) DEFAULT NULL, network VARCHAR(64) DEFAULT NULL, security VARCHAR(64) DEFAULT NULL, is_active TINYINT(1) NOT NULL, config JSON DEFAULT NULL, last_synced_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_AA50B7A79EB6921 (panel_id), UNIQUE INDEX uniq_vpn_inbound_panel_remote_id (panel_id, remote_inbound_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE vpn_inbound ADD CONSTRAINT FK_AA50B7A79EB6921 FOREIGN KEY (panel_id) REFERENCES vpn_panel (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE plan ADD inbound_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE plan ADD CONSTRAINT FK_2B0C13F8DCEB8A FOREIGN KEY (inbound_id) REFERENCES vpn_inbound (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2B0C13F8DCEB8A ON plan (inbound_id)');

        $this->addSql('ALTER TABLE vpn_service ADD inbound_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vpn_service ADD CONSTRAINT FK_6A0B90E9DCEB8A FOREIGN KEY (inbound_id) REFERENCES vpn_inbound (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6A0B90E9DCEB8A ON vpn_service (inbound_id)');

        $this->addSql('ALTER TABLE plan DROP FOREIGN KEY FK_2B0C13F89EB6921');
        $this->addSql('DROP INDEX IDX_2B0C13F89EB6921 ON plan');
        $this->addSql('ALTER TABLE plan DROP panel_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan ADD panel_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE plan ADD CONSTRAINT FK_2B0C13F89EB6921 FOREIGN KEY (panel_id) REFERENCES vpn_panel (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2B0C13F89EB6921 ON plan (panel_id)');

        $this->addSql('ALTER TABLE plan DROP FOREIGN KEY FK_2B0C13F8DCEB8A');
        $this->addSql('DROP INDEX IDX_2B0C13F8DCEB8A ON plan');
        $this->addSql('ALTER TABLE plan DROP inbound_id');

        $this->addSql('ALTER TABLE vpn_service DROP FOREIGN KEY FK_6A0B90E9DCEB8A');
        $this->addSql('DROP INDEX IDX_6A0B90E9DCEB8A ON vpn_service');
        $this->addSql('ALTER TABLE vpn_service DROP inbound_id');

        $this->addSql('ALTER TABLE vpn_inbound DROP FOREIGN KEY FK_AA50B7A79EB6921');
        $this->addSql('DROP TABLE vpn_inbound');
    }
}
