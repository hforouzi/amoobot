<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add usage sync and status check lifecycle fields to vpn_service';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_service ADD traffic_limit_bytes BIGINT DEFAULT NULL, ADD traffic_used_bytes BIGINT DEFAULT NULL, ADD last_usage_synced_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD last_status_checked_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_service DROP traffic_limit_bytes, DROP traffic_used_bytes, DROP last_usage_synced_at, DROP last_status_checked_at');
    }
}

