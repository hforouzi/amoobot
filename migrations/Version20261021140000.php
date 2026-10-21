<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Provisioning\Domain\SubscriptionUrlRepair;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data only: repairs vpn_service.subscription_url values stored with the sub id appended twice (".../sub/<id>/<id>"),
 * a trailing slash, or as a relative "/<id>" (no subscription base configured), see SubscriptionUrlRepair.
 */
final class Version20261021140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'vpn_service: repair subscription URLs stored with a duplicated sub id / trailing slash / relative path';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, subscription_url, sub_id FROM vpn_service WHERE subscription_url IS NOT NULL');
        foreach ($rows as $row) {
            $stored = (string) $row['subscription_url'];
            $repaired = SubscriptionUrlRepair::repair($stored, null !== $row['sub_id'] ? (string) $row['sub_id'] : null);
            if ($repaired !== $stored) {
                $this->addSql('UPDATE vpn_service SET subscription_url = ? WHERE id = ?', [$repaired, (int) $row['id']]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // The broken values are not restored.
    }
}
