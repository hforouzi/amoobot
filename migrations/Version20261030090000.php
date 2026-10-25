<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Claim links, phase S2 (docs/design/reseller-and-claim.md, 1.5 / 1.6): service_claim (a single-use, expiring link
 * that moves one service to the Telegram user who opens it; only the SHA-256 of its token is stored) and
 * service_ownership_event (the append-only history of owner changes). Additive only: two new tables, no change to any
 * existing table or row, so up() is online-safe.
 *
 * At most one open link per service: open_service_id is the service id while the link is open and NULL otherwise, under
 * a unique key (MySQL / MariaDB have no partial index; NULLs never collide).
 *
 * down() is refused (nothing is changed) while either table has a row: dropping them would lose who owned a service
 * before and which link moved it.
 */
final class Version20261030090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'claim links (S2): service_claim, service_ownership_event';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE service_claim (id INT AUTO_INCREMENT NOT NULL, service_id INT NOT NULL, reseller_id INT DEFAULT NULL, from_user_id INT NOT NULL, created_by_user_id INT DEFAULT NULL, created_by_admin_id INT DEFAULT NULL, token_hash CHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, open_service_id INT DEFAULT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, claimed_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, expired_at DATETIME DEFAULT NULL, claimed_by_user_id INT DEFAULT NULL, claimed_by_telegram_id VARCHAR(32) DEFAULT NULL, revoked_by_user_id INT DEFAULT NULL, revoked_by_admin_id INT DEFAULT NULL, revoke_reason VARCHAR(32) DEFAULT NULL, panel_sync_status VARCHAR(16) DEFAULT NULL, panel_sync_attempts INT DEFAULT 0 NOT NULL, panel_sync_next_at DATETIME DEFAULT NULL, panel_synced_at DATETIME DEFAULT NULL, panel_sync_error VARCHAR(255) DEFAULT NULL, INDEX IDX_E1B33C0ED5CA9E6 (service_id), INDEX IDX_E1B33C091E6A19D (reseller_id), INDEX IDX_E1B33C02130303A (from_user_id), INDEX IDX_E1B33C0F8310F52 (claimed_by_user_id), INDEX idx_service_claim_reseller (reseller_id, status, created_at), INDEX idx_service_claim_status_expires (status, expires_at), INDEX idx_service_claim_panel_sync (panel_sync_status, panel_sync_next_at), UNIQUE INDEX uniq_service_claim_token_hash (token_hash), UNIQUE INDEX uniq_service_claim_open_service (open_service_id), CONSTRAINT chk_service_claim_status CHECK (status IN (\'open\', \'claimed\', \'revoked\', \'expired\')), CONSTRAINT chk_service_claim_open_service CHECK ((status = \'open\') = (open_service_id IS NOT NULL)), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE service_ownership_event (id INT AUTO_INCREMENT NOT NULL, service_id INT NOT NULL, from_user_id INT NOT NULL, to_user_id INT NOT NULL, claim_id INT DEFAULT NULL, actor_type VARCHAR(16) NOT NULL, actor_admin_id INT DEFAULT NULL, reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_5731FB38ED5CA9E6 (service_id), INDEX IDX_5731FB382130303A (from_user_id), INDEX IDX_5731FB3829F6EE60 (to_user_id), INDEX IDX_5731FB387096A49F (claim_id), INDEX idx_service_ownership_event_service (service_id, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE service_claim ADD CONSTRAINT FK_E1B33C0ED5CA9E6 FOREIGN KEY (service_id) REFERENCES vpn_service (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_claim ADD CONSTRAINT FK_E1B33C091E6A19D FOREIGN KEY (reseller_id) REFERENCES reseller (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_claim ADD CONSTRAINT FK_E1B33C02130303A FOREIGN KEY (from_user_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_claim ADD CONSTRAINT FK_E1B33C0F8310F52 FOREIGN KEY (claimed_by_user_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_ownership_event ADD CONSTRAINT FK_5731FB38ED5CA9E6 FOREIGN KEY (service_id) REFERENCES vpn_service (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_ownership_event ADD CONSTRAINT FK_5731FB382130303A FOREIGN KEY (from_user_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_ownership_event ADD CONSTRAINT FK_5731FB3829F6EE60 FOREIGN KEY (to_user_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE service_ownership_event ADD CONSTRAINT FK_5731FB387096A49F FOREIGN KEY (claim_id) REFERENCES service_claim (id) ON DELETE RESTRICT');
    }

    /**
     * Refused while either table has a row (see the class comment); checked before anything is touched.
     */
    public function down(Schema $schema): void
    {
        $claims = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_claim');
        $this->abortIf($claims > 0, sprintf('Irreversible: %d claim link(s) exist; dropping service_claim would lose which link moved which service. Nothing was changed.', $claims));
        $events = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM service_ownership_event');
        $this->abortIf($events > 0, sprintf('Irreversible: %d ownership change(s) are recorded; dropping service_ownership_event would lose who owned a service before. Nothing was changed.', $events));

        $this->addSql('DROP TABLE service_ownership_event');
        $this->addSql('DROP TABLE service_claim');
    }
}
