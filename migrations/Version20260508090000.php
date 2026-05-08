<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260508090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add inbound access metadata, panel subscription/public host, plan ipLimit, and vpn service access identity/link fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan ADD ip_limit INT DEFAULT NULL');

        $this->addSql('ALTER TABLE vpn_panel ADD subscription_base_url VARCHAR(255) DEFAULT NULL, ADD public_host VARCHAR(255) DEFAULT NULL');

        $this->addSql('ALTER TABLE vpn_inbound ADD host VARCHAR(255) DEFAULT NULL, ADD port INT DEFAULT NULL, ADD sni VARCHAR(255) DEFAULT NULL, ADD path VARCHAR(255) DEFAULT NULL, ADD host_header VARCHAR(255) DEFAULT NULL, ADD public_key VARCHAR(255) DEFAULT NULL, ADD short_id VARCHAR(255) DEFAULT NULL, ADD spider_x VARCHAR(255) DEFAULT NULL, ADD flow VARCHAR(255) DEFAULT NULL, ADD service_name VARCHAR(255) DEFAULT NULL, ADD fingerprint VARCHAR(255) DEFAULT NULL, ADD alpn VARCHAR(255) DEFAULT NULL, ADD last_access_metadata_synced_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');

        $this->addSql('ALTER TABLE vpn_service ADD client_uuid VARCHAR(64) DEFAULT NULL, ADD client_email VARCHAR(255) DEFAULT NULL, ADD sub_id VARCHAR(64) DEFAULT NULL, ADD ip_limit INT DEFAULT NULL, ADD config_links JSON DEFAULT NULL, ADD last_access_info_synced_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_service DROP client_uuid, DROP client_email, DROP sub_id, DROP ip_limit, DROP config_links, DROP last_access_info_synced_at');
        $this->addSql('ALTER TABLE vpn_inbound DROP host, DROP port, DROP sni, DROP path, DROP host_header, DROP public_key, DROP short_id, DROP spider_x, DROP flow, DROP service_name, DROP fingerprint, DROP alpn, DROP last_access_metadata_synced_at');
        $this->addSql('ALTER TABLE vpn_panel DROP subscription_base_url, DROP public_host');
        $this->addSql('ALTER TABLE plan DROP ip_limit');
    }
}

