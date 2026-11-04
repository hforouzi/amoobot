<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A plan's inbound change reaching existing services, PR P3 (docs/design/plan-inbound-change.md 4.3, 5.1, 5.5, 9):
 * the schema only. Two new tables, inbound_move_run (a run per plan) and service_inbound_move (one row per service in
 * a run, the 4.3 state machine with the retry columns of vpn_service_inbound), and one nullable column,
 * vpn_service.inbound_changed_at. Additive only: no existing row changes, no backfill, the column starts NULL, so up()
 * is online-safe. Nothing reads or writes any of it yet (the runner comes in P4/P6).
 *
 * - A run is removed with its plan, and its move rows with it; a move row with its service (ON DELETE CASCADE).
 * - Inbound references are ON DELETE SET NULL: a run or a move row never blocks deleting an inbound; the move row keeps
 *   the panel's inbound ids (from/to_remote_inbound_id) so an old inbound can still be named after a local delete.
 * - One open run (planned, running, paused) per plan: open_plan_id is the plan's id while the run is open and NULL
 *   otherwise, under a unique index; the CHECK constraint keeps it in step with the status for every writer, so the
 *   database refuses a second open run. Closed runs are not limited.
 * - One move per service per run: unique (run_id, service_id). The due-item queries of the runner use
 *   (state, next_attempt_at) and (state, detach_after); rows are claimed and locked by primary key (5.3).
 *
 * down() is refused (nothing is changed) while either table has a row or any service has inbound_changed_at set:
 * dropping them would lose which services were moved and which old inbounds are still to be removed on the panel.
 */
final class Version20261104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'plan inbound change (P3): inbound_move_run, service_inbound_move, vpn_service.inbound_changed_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbound_move_run (id INT AUTO_INCREMENT NOT NULL, open_plan_id INT DEFAULT NULL, kind VARCHAR(16) NOT NULL, scope JSON NOT NULL, status VARCHAR(16) NOT NULL, pause_reason VARCHAR(255) DEFAULT NULL, grace_hours SMALLINT NOT NULL, keep_old TINYINT NOT NULL, requested_by INT DEFAULT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, planned_count INT NOT NULL, done_count INT NOT NULL, failed_count INT NOT NULL, skipped_count INT NOT NULL, remaining_count INT NOT NULL, plan_id INT NOT NULL, target_inbound_id INT DEFAULT NULL, INDEX IDX_AEB04DA3E899029B (plan_id), INDEX IDX_AEB04DA3D7AC7789 (target_inbound_id), INDEX idx_inbound_move_run_status (status), UNIQUE INDEX uniq_inbound_move_run_open_plan (open_plan_id), PRIMARY KEY (id), CONSTRAINT chk_inbound_move_run_open_plan CHECK ((status IN (\'planned\', \'running\', \'paused\') AND open_plan_id IS NOT NULL AND open_plan_id = plan_id) OR (status NOT IN (\'planned\', \'running\', \'paused\') AND open_plan_id IS NULL))) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE service_inbound_move (id INT AUTO_INCREMENT NOT NULL, from_remote_inbound_id VARCHAR(64) DEFAULT NULL, to_remote_inbound_id VARCHAR(64) DEFAULT NULL, state VARCHAR(16) NOT NULL, skip_reason VARCHAR(64) DEFAULT NULL, attempts INT NOT NULL, claimed_at DATETIME DEFAULT NULL, next_attempt_at DATETIME DEFAULT NULL, detach_after DATETIME DEFAULT NULL, detached_at DATETIME DEFAULT NULL, last_error VARCHAR(255) DEFAULT NULL, alert_state VARCHAR(16) DEFAULT NULL, alerted_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, run_id INT NOT NULL, service_id INT NOT NULL, from_inbound_id INT DEFAULT NULL, to_inbound_id INT DEFAULT NULL, INDEX IDX_9D8D976B84E3FEC4 (run_id), INDEX IDX_9D8D976BED5CA9E6 (service_id), INDEX IDX_9D8D976BCEE4691F (from_inbound_id), INDEX IDX_9D8D976BC2734D65 (to_inbound_id), INDEX idx_service_inbound_move_due (state, next_attempt_at), INDEX idx_service_inbound_move_detach (state, detach_after), UNIQUE INDEX uniq_service_inbound_move_run_service (run_id, service_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE inbound_move_run ADD CONSTRAINT FK_AEB04DA3E899029B FOREIGN KEY (plan_id) REFERENCES plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE inbound_move_run ADD CONSTRAINT FK_AEB04DA3D7AC7789 FOREIGN KEY (target_inbound_id) REFERENCES vpn_inbound (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_inbound_move ADD CONSTRAINT FK_9D8D976B84E3FEC4 FOREIGN KEY (run_id) REFERENCES inbound_move_run (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_inbound_move ADD CONSTRAINT FK_9D8D976BED5CA9E6 FOREIGN KEY (service_id) REFERENCES vpn_service (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE service_inbound_move ADD CONSTRAINT FK_9D8D976BCEE4691F FOREIGN KEY (from_inbound_id) REFERENCES vpn_inbound (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE service_inbound_move ADD CONSTRAINT FK_9D8D976BC2734D65 FOREIGN KEY (to_inbound_id) REFERENCES vpn_inbound (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE vpn_service ADD inbound_changed_at DATETIME DEFAULT NULL');
    }

    /**
     * Refused while any of it holds data (see the class comment); checked before anything is touched.
     */
    public function down(Schema $schema): void
    {
        $runs = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM inbound_move_run');
        $this->abortIf($runs > 0, sprintf('Irreversible: %d inbound move run(s) exist; dropping inbound_move_run would lose which plans moved their services and what is still to do. Nothing was changed.', $runs));
        $moves = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_inbound_move');
        $this->abortIf($moves > 0, sprintf('Irreversible: %d service inbound move(s) exist; dropping service_inbound_move would lose which services were moved and which old inbounds are still to be removed on the panel. Nothing was changed.', $moves));
        $changed = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM vpn_service WHERE inbound_changed_at IS NOT NULL');
        $this->abortIf($changed > 0, sprintf('Irreversible: %d service(s) have vpn_service.inbound_changed_at set; dropping it would show links of an inbound those services left. Nothing was changed.', $changed));

        $this->addSql('DROP TABLE service_inbound_move');
        $this->addSql('DROP TABLE inbound_move_run');
        $this->addSql('ALTER TABLE vpn_service DROP inbound_changed_at');
    }
}
