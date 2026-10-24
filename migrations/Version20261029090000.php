<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * order.reseller_nonce: the one-time confirmation a reseller order was created from (S1 review, B1), unique per
 * reseller, so a second delivery of the same confirmation never creates a second order or debit. Additive: a nullable
 * column without a default (instant on MariaDB 10.3+ / MySQL 8.0.12+, every existing row NULL) and a unique index built
 * online (ALGORITHM=INPLACE, LOCK=NONE); NULLs never collide in a unique index, so existing orders are unaffected.
 *
 * down() is refused (nothing is changed) while any order carries a nonce: without the unique key a replayed
 * confirmation could buy again.
 */
final class Version20261029090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'order.reseller_nonce (one-time reseller purchase confirmations, unique per reseller)';
    }

    public function up(Schema $schema): void
    {
        $online = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ', ALGORITHM=INPLACE, LOCK=NONE' : '';
        $this->addSql('ALTER TABLE `order` ADD reseller_nonce VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE `order` ADD UNIQUE INDEX uniq_order_reseller_nonce (reseller_id, reseller_nonce)'.$online);
    }

    public function down(Schema $schema): void
    {
        $withNonce = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `order` WHERE reseller_nonce IS NOT NULL');
        $this->abortIf($withNonce > 0, sprintf('Irreversible: %d reseller order(s) carry a confirmation nonce; without its unique key a replayed confirmation could buy twice. Nothing was changed.', $withNonce));

        $this->addSql('ALTER TABLE `order` DROP INDEX uniq_order_reseller_nonce, DROP reseller_nonce');
    }
}
