<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Panel client import (docs/design/panel-client-import.md): one nullable column, vpn_service.imported_at, set when
 * app:panel:import-clients created the service from a client found on the panel (no order, no plan). Additive only: no
 * existing row changes, the column starts NULL, so up() is online-safe.
 *
 * down() is refused (nothing is changed) while any service has imported_at set: dropping it would lose which services
 * were imported rather than sold.
 */
final class Version20261108090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'panel client import: vpn_service.imported_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_service ADD imported_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $imported = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM vpn_service WHERE imported_at IS NOT NULL');
        $this->abortIf($imported > 0, sprintf('Irreversible: %d service(s) have vpn_service.imported_at set; dropping it would lose which services were imported from a panel. Nothing was changed.', $imported));

        $this->addSql('ALTER TABLE vpn_service DROP imported_at');
    }
}
