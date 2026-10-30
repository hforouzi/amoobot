<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plans with several inbounds, phase M2c (docs/design/multi-inbound-plans.md, "M2c as built", F7): the scheduled
 * reconcile re-attaches an inbound that was removed from a client on the panel; when something keeps removing it, that
 * would loop for ever. Per vpn_service_inbound row it now counts the re-attach cycles within a rolling window
 * (reattach_count since reattach_since); past the limit the automatic re-attach stops and the admins are alerted once.
 * Additive only: two columns, the counter defaulting to 0, so up() is online-safe and every existing row starts with
 * no cycles counted.
 *
 * down() drops them; only the cycle count is lost (a row stopped for looping stays stopped: its attempts are used up).
 */
final class Version20261103090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'plans with several inbounds (M2c): re-attach cycle counter of vpn_service_inbound';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_service_inbound ADD reattach_count SMALLINT DEFAULT 0 NOT NULL, ADD reattach_since DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_service_inbound DROP reattach_count, DROP reattach_since');
    }
}
