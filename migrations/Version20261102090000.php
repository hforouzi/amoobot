<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plans with several inbounds, phase M2b (docs/design/multi-inbound-plans.md, "M2b as built"): the scheduled retry of a
 * service's additional inbounds (app:services:attach-inbounds) needs, per vpn_service_inbound row, when it may be tried
 * again (next_attempt_at: exponential back-off; NULL on a failed row means "due now"), when the panel was last read for
 * it (checked_at: the reconcile with the panel), and which admin alert was sent for it (alert_state, alerted_at: "alert
 * once"). Additive only: four nullable columns and one index, no backfill, so up() is online-safe and every existing
 * row keeps its meaning (a failed row without next_attempt_at is simply due at the next run).
 *
 * down() drops them. Nothing a customer or a panel depends on is lost: only the schedule (every failed row is due at
 * once after an up() again) and which alerts were sent (an alert may be sent once more).
 */
final class Version20261102090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'plans with several inbounds (M2b): retry schedule, panel check and alert state of vpn_service_inbound';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_service_inbound ADD next_attempt_at DATETIME DEFAULT NULL, ADD checked_at DATETIME DEFAULT NULL, ADD alert_state VARCHAR(16) DEFAULT NULL, ADD alerted_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_vpn_service_inbound_due ON vpn_service_inbound (status, next_attempt_at)');
    }

    public function down(Schema $schema): void
    {
        // M2c added columns to vpn_service_inbound too: M1's down() would otherwise drop the table under it.
        $m2c = false !== $this->connection->fetchOne("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vpn_service_inbound' AND COLUMN_NAME = 'reattach_count'");
        $this->abortIf($m2c, 'Roll back Version20261103090000 (re-attach cycles of additional inbounds) first. Nothing was changed.');
        $this->addSql('DROP INDEX idx_vpn_service_inbound_due ON vpn_service_inbound');
        $this->addSql('ALTER TABLE vpn_service_inbound DROP next_attempt_at, DROP checked_at, DROP alert_state, DROP alerted_at');
    }
}
