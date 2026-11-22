<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PM2a (docs/design/product-modules.md 3.2, 3.6): the product type of plans and services. Additive only, and the
 * defaults reproduce today exactly: every existing plan and service reads 'vpn', the one module there is, and a row
 * inserted by code that does not know the column gets 'vpn' too. plan.module_config starts NULL and is unused for now.
 * The table and entity names stay (3.1).
 *
 * The ADD COLUMNs are instant on MariaDB (appended columns with a constant default: metadata only, no table copy;
 * accepted with ALGORITHM=INSTANT on 10.8.3, and 10.11 does at least the same); the index is built online
 * (accepted with ALGORITHM=INPLACE, LOCK=NONE: reads and writes go on). 10,000 services: about 75 ms in all.
 *
 * down() is refused (nothing is changed) while any plan or service has a type other than 'vpn' or a plan has
 * module_config set: dropping the columns would make those rows read as VPN rows.
 */
final class Version20261122090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'product modules PM2a: plan.product_type, plan.module_config, vpn_service.product_type';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE plan ADD product_type VARCHAR(32) DEFAULT 'vpn' NOT NULL, ADD module_config JSON DEFAULT NULL");
        $this->addSql("ALTER TABLE vpn_service ADD product_type VARCHAR(32) DEFAULT 'vpn' NOT NULL");
        $this->addSql('CREATE INDEX idx_vpn_service_type_status ON vpn_service (product_type, status)');
    }

    public function down(Schema $schema): void
    {
        $plans = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM plan WHERE product_type <> 'vpn' OR module_config IS NOT NULL");
        $services = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM vpn_service WHERE product_type <> 'vpn'");
        $this->abortIf($plans + $services > 0, sprintf('Irreversible: %d plan(s) and %d service(s) are not plain VPN rows (product_type or module_config set); dropping the columns would make them read as VPN. Nothing was changed.', $plans, $services));

        $this->addSql('DROP INDEX idx_vpn_service_type_status ON vpn_service');
        $this->addSql('ALTER TABLE vpn_service DROP product_type');
        $this->addSql('ALTER TABLE plan DROP product_type, DROP module_config');
    }
}
