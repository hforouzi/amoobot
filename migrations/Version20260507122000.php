<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260507122000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable panel relation to plan table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan ADD panel_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE plan ADD CONSTRAINT FK_2B0C13F89EB6921 FOREIGN KEY (panel_id) REFERENCES vpn_panel (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2B0C13F89EB6921 ON plan (panel_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan DROP FOREIGN KEY FK_2B0C13F89EB6921');
        $this->addSql('DROP INDEX IDX_2B0C13F89EB6921 ON plan');
        $this->addSql('ALTER TABLE plan DROP panel_id');
    }
}
