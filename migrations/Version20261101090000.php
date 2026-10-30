<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plans with several inbounds, phase M1 (docs/design/multi-inbound-plans.md 2.1-2.3): a plan's additional inbounds
 * (plan_additional_inbound; the primary stays plan.inbound) and, for phase M2, a service's additional inbounds with the
 * state of their attachment (vpn_service_inbound; the primary stays vpn_service.inbound). Additive only: two new tables,
 * no change to any existing table or row, no backfill (every existing plan and service is single-inbound: no rows), so
 * up() is online-safe.
 *
 * A row never blocks deleting its plan or its service (ON DELETE CASCADE, also when a user is deleted with his
 * services). The inbound is RESTRICT on purpose: an inbound a plan sells or a service is attached to cannot be deleted
 * (the admin pages refuse it first, naming the plans).
 *
 * down() is refused (nothing is changed) while either table has a row: dropping them would lose which inbounds a plan
 * sells or a service holds, and services would keep inbounds on the panel that the bot no longer knows about.
 */
final class Version20261101090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'plans with several inbounds (M1): plan_additional_inbound, vpn_service_inbound';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plan_additional_inbound (id INT AUTO_INCREMENT NOT NULL, plan_id INT NOT NULL, inbound_id INT NOT NULL, sort_order INT NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_B0158CF5E899029B (plan_id), INDEX IDX_B0158CF5E540AF18 (inbound_id), UNIQUE INDEX uniq_plan_additional_inbound (plan_id, inbound_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE vpn_service_inbound (id INT AUTO_INCREMENT NOT NULL, service_id INT NOT NULL, inbound_id INT NOT NULL, remote_inbound_id VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL, last_error VARCHAR(255) DEFAULT NULL, attached_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, INDEX IDX_48C79476ED5CA9E6 (service_id), INDEX IDX_48C79476E540AF18 (inbound_id), UNIQUE INDEX uniq_vpn_service_inbound (service_id, inbound_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE plan_additional_inbound ADD CONSTRAINT FK_B0158CF5E899029B FOREIGN KEY (plan_id) REFERENCES plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE plan_additional_inbound ADD CONSTRAINT FK_B0158CF5E540AF18 FOREIGN KEY (inbound_id) REFERENCES vpn_inbound (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE vpn_service_inbound ADD CONSTRAINT FK_48C79476ED5CA9E6 FOREIGN KEY (service_id) REFERENCES vpn_service (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vpn_service_inbound ADD CONSTRAINT FK_48C79476E540AF18 FOREIGN KEY (inbound_id) REFERENCES vpn_inbound (id) ON DELETE RESTRICT');
    }

    /**
     * Refused while either table has a row (see the class comment); checked before anything is touched.
     */
    public function down(Schema $schema): void
    {
        // M2b added columns to vpn_service_inbound: dropping the table first would leave M2b recorded without them.
        $m2b = false !== $this->connection->fetchOne("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vpn_service_inbound' AND COLUMN_NAME = 'next_attempt_at'");
        $this->abortIf($m2b, 'Roll back Version20261102090000 (retry schedule of additional inbounds) first. Nothing was changed.');
        $plans = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM plan_additional_inbound');
        $this->abortIf($plans > 0, sprintf('Irreversible: %d additional plan inbound(s) exist; dropping plan_additional_inbound would lose which inbounds those plans sell. Remove the additional inbounds from the plans first. Nothing was changed.', $plans));
        $services = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM vpn_service_inbound');
        $this->abortIf($services > 0, sprintf('Irreversible: %d service inbound attachment(s) exist; dropping vpn_service_inbound would lose which inbounds those services hold on the panel. Nothing was changed.', $services));

        $this->addSql('DROP TABLE vpn_service_inbound');
        $this->addSql('DROP TABLE plan_additional_inbound');
    }
}
