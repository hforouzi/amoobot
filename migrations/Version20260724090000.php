<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add explicit Sanaei integration mode to VPN panels';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_panel ADD sanaei_integration_mode VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_panel DROP sanaei_integration_mode');
    }
}
