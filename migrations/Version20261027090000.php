<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Resellers, phase S1 (docs/design/reseller-and-claim.md): the reseller profile, per-plan wholesale rules, which reseller
 * bought an order (with its wholesale / retail / floor snapshot), which reseller sold a service, and a plan's reseller
 * floor price. Additive only: new tables and nullable columns without defaults (an instant ADD COLUMN on MariaDB 10.3+ /
 * MySQL 8.0.12+), no backfill; every existing order, service and plan is unchanged.
 *
 * The indexes on `order` / vpn_service are built online (ALGORITHM=INPLACE, LOCK=NONE). Their foreign keys are added
 * with foreign_key_checks off, which lets the server add them in place instead of copying the table; that is safe here
 * because the columns were just added and are NULL in every row, so there is nothing to check.
 *
 * down() is refused (nothing is changed) while any reseller data exists: a reseller profile, an order or service
 * attributed to one, or a plan floor price. Dropping them would lose who bought what at which price.
 */
final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'resellers (S1): reseller, reseller_plan_price, order/service attribution, plan.reseller_floor_amount';
    }

    public function up(Schema $schema): void
    {
        $online = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ', ALGORITHM=INPLACE, LOCK=NONE' : '';

        $this->addSql("CREATE TABLE reseller (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, status VARCHAR(16) DEFAULT 'active' NOT NULL, discount_bp INT DEFAULT 0 NOT NULL, daily_spend_limit BIGINT DEFAULT NULL, display_name VARCHAR(64) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, disabled_at DATETIME DEFAULT NULL, created_by_admin_id INT DEFAULT NULL, INDEX idx_reseller_status (status), UNIQUE INDEX uniq_reseller_user (user_id), CONSTRAINT chk_reseller_discount CHECK (discount_bp BETWEEN 0 AND 9000), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE TABLE reseller_plan_price (id INT AUTO_INCREMENT NOT NULL, reseller_id INT NOT NULL, plan_id INT NOT NULL, discount_bp INT DEFAULT NULL, fixed_amount BIGINT DEFAULT NULL, updated_at DATETIME NOT NULL, updated_by_admin_id INT DEFAULT NULL, INDEX IDX_4A99A0ED91E6A19D (reseller_id), INDEX IDX_4A99A0EDE899029B (plan_id), UNIQUE INDEX uniq_reseller_plan_price (reseller_id, plan_id), CONSTRAINT chk_reseller_plan_price_rule CHECK ((discount_bp IS NULL) <> (fixed_amount IS NULL)), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reseller ADD CONSTRAINT FK_18015899A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE reseller_plan_price ADD CONSTRAINT FK_4A99A0ED91E6A19D FOREIGN KEY (reseller_id) REFERENCES reseller (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reseller_plan_price ADD CONSTRAINT FK_4A99A0EDE899029B FOREIGN KEY (plan_id) REFERENCES plan (id) ON DELETE RESTRICT');

        $this->addSql('ALTER TABLE `order` ADD reseller_id INT DEFAULT NULL, ADD wholesale_amount INT DEFAULT NULL, ADD retail_amount INT DEFAULT NULL, ADD reseller_floor_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `order` ADD INDEX IDX_F529939891E6A19D (reseller_id), ADD INDEX idx_order_reseller (reseller_id, status, created_at)'.$online);
        $this->addSql('ALTER TABLE vpn_service ADD sold_by_reseller_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE vpn_service ADD INDEX IDX_EF0F523425E4B3DC (sold_by_reseller_id), ADD INDEX idx_vpn_service_sold_by (sold_by_reseller_id, status)'.$online);
        $this->addSql('ALTER TABLE plan ADD reseller_floor_amount INT DEFAULT NULL');

        // The new columns are NULL in every row: nothing to check, so no table copy.
        $this->addSql('SET foreign_key_checks = 0');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F529939891E6A19D FOREIGN KEY (reseller_id) REFERENCES reseller (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE vpn_service ADD CONSTRAINT FK_EF0F523425E4B3DC FOREIGN KEY (sold_by_reseller_id) REFERENCES reseller (id) ON DELETE RESTRICT');
        $this->addSql('SET foreign_key_checks = 1');
    }

    /**
     * Refused while reseller data exists (see the class comment); checked before anything is touched.
     */
    public function down(Schema $schema): void
    {
        // A later migration put a unique key on order.reseller_id: rolling this one back first would drop half of it.
        $nonce = false !== $this->connection->fetchOne("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order' AND COLUMN_NAME = 'reseller_nonce'");
        $this->abortIf($nonce, 'Roll back Version20261029090000 (order.reseller_nonce) first. Nothing was changed.');
        $resellers = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reseller');
        $this->abortIf($resellers > 0, sprintf('Irreversible: %d reseller profile(s) exist; dropping reseller would lose them and their wholesale rules. Nothing was changed.', $resellers));
        $orders = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `order` WHERE reseller_id IS NOT NULL OR wholesale_amount IS NOT NULL OR retail_amount IS NOT NULL OR reseller_floor_amount IS NOT NULL');
        $this->abortIf($orders > 0, sprintf('Irreversible: %d order(s) carry a reseller price snapshot; dropping it would lose what was paid at wholesale. Nothing was changed.', $orders));
        $services = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM vpn_service WHERE sold_by_reseller_id IS NOT NULL');
        $this->abortIf($services > 0, sprintf('Irreversible: %d service(s) were sold by a reseller; dropping that would lose who sold them. Nothing was changed.', $services));
        $plans = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM plan WHERE reseller_floor_amount IS NOT NULL');
        $this->abortIf($plans > 0, sprintf('Irreversible: %d plan(s) have a reseller floor price; dropping it would lose the admin\'s setting. Nothing was changed.', $plans));
        // Claim links (S2) point at reseller: rolling this one back first would fail half-way on their foreign key.
        $claims = false !== $this->connection->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_claim'");
        $this->abortIf($claims, 'Roll back Version20261030090000 (claim links) first. Nothing was changed.');
        // The reseller Mini App's customer labels (S3) point at reseller too.
        $labels = false !== $this->connection->fetchOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reseller_customer'");
        $this->abortIf($labels, 'Roll back Version20261031090000 (reseller customer labels) first. Nothing was changed.');

        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F529939891E6A19D');
        $this->addSql('ALTER TABLE vpn_service DROP FOREIGN KEY FK_EF0F523425E4B3DC');
        $this->addSql('ALTER TABLE `order` DROP INDEX idx_order_reseller, DROP INDEX IDX_F529939891E6A19D, DROP reseller_id, DROP wholesale_amount, DROP retail_amount, DROP reseller_floor_amount');
        $this->addSql('ALTER TABLE vpn_service DROP INDEX idx_vpn_service_sold_by, DROP INDEX IDX_EF0F523425E4B3DC, DROP sold_by_reseller_id');
        $this->addSql('ALTER TABLE plan DROP reseller_floor_amount');
        $this->addSql('DROP TABLE reseller_plan_price');
        $this->addSql('DROP TABLE reseller');
    }
}
