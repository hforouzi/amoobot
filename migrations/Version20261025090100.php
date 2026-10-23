<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The wallet ledger is financial history: its foreign keys become ON DELETE RESTRICT instead of CASCADE, so deleting a
 * customer (or a wallet, or a reversed row) can never silently erase ledger rows in the database. The application never
 * deletes these rows (WalletLedgerGuard refuses it through the ORM); this closes the database-level path too.
 *
 * Safe on existing data: only the delete action changes; every existing row already satisfies the keys, so no row is
 * changed. The tables are small (one row per wallet / movement); MariaDB rebuilds the foreign keys quickly.
 */
final class Version20261025090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'wallet ledger foreign keys: ON DELETE RESTRICT instead of CASCADE';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wallet DROP FOREIGN KEY FK_7C68921FA76ED395');
        $this->addSql('ALTER TABLE wallet ADD CONSTRAINT FK_7C68921FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE wallet_transaction DROP FOREIGN KEY FK_7DAF97229A0BB4E');
        $this->addSql('ALTER TABLE wallet_transaction DROP FOREIGN KEY FK_7DAF972712520F3');
        $this->addSql('ALTER TABLE wallet_transaction ADD CONSTRAINT FK_7DAF97229A0BB4E FOREIGN KEY (reversal_of_id) REFERENCES wallet_transaction (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE wallet_transaction ADD CONSTRAINT FK_7DAF972712520F3 FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wallet DROP FOREIGN KEY FK_7C68921FA76ED395');
        $this->addSql('ALTER TABLE wallet ADD CONSTRAINT FK_7C68921FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wallet_transaction DROP FOREIGN KEY FK_7DAF97229A0BB4E');
        $this->addSql('ALTER TABLE wallet_transaction DROP FOREIGN KEY FK_7DAF972712520F3');
        $this->addSql('ALTER TABLE wallet_transaction ADD CONSTRAINT FK_7DAF97229A0BB4E FOREIGN KEY (reversal_of_id) REFERENCES wallet_transaction (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wallet_transaction ADD CONSTRAINT FK_7DAF972712520F3 FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE CASCADE');
    }
}
