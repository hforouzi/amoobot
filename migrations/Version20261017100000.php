<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sanaei integration mode (auto by default) and the result of the last capability probe. Additive.
 * The column name matches the unmerged feat/sanaei-modern-* branches, so a database that ran their migration
 * keeps its column (only made non-null with "auto" for rows that had none).
 */
final class Version20261017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'vpn_panel: sanaei integration mode (auto/legacy/modern) and capability probe result';
    }

    public function up(Schema $schema): void
    {
        if ($schema->getTable('vpn_panel')->hasColumn('sanaei_integration_mode')) {
            $this->addSql("UPDATE vpn_panel SET sanaei_integration_mode = 'auto' WHERE sanaei_integration_mode IS NULL OR sanaei_integration_mode = ''");
            $this->addSql("ALTER TABLE vpn_panel MODIFY sanaei_integration_mode VARCHAR(32) DEFAULT 'auto' NOT NULL");
        } else {
            $this->addSql("ALTER TABLE vpn_panel ADD sanaei_integration_mode VARCHAR(32) DEFAULT 'auto' NOT NULL");
        }
        $this->addSql('ALTER TABLE vpn_panel ADD sanaei_detected_mode VARCHAR(16) DEFAULT NULL, ADD sanaei_probed_at DATETIME DEFAULT NULL, ADD sanaei_probe_status VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_panel DROP sanaei_integration_mode, DROP sanaei_detected_mode, DROP sanaei_probed_at, DROP sanaei_probe_status');
    }
}
