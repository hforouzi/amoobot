<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\VpnService;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * vpn_service.public_token: the random token of the customer's public subscription page (/s/{token}). Existing
 * services get one here (random_bytes, not SQL), then the column becomes required and unique.
 */
final class Version20261022090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'vpn_service: public_token for the public subscription page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_service ADD public_token VARCHAR(64) DEFAULT NULL');
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM vpn_service') as $id) {
            $this->addSql('UPDATE vpn_service SET public_token = ? WHERE id = ?', [VpnService::generatePublicToken(), (int) $id]);
        }
        $this->addSql('ALTER TABLE vpn_service MODIFY public_token VARCHAR(64) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_vpn_service_public_token ON vpn_service (public_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_vpn_service_public_token ON vpn_service');
        $this->addSql('ALTER TABLE vpn_service DROP public_token');
    }
}
