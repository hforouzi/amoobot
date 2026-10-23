<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wallets: one per user (created lazily on first use, so no backfill), and their append-only ledger.
 * Amounts are integer Toman. Unique idempotency_key (no movement applied twice) and unique reversal_of_id (a row is
 * reversed at most once).
 */
final class Version20261024090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'wallet and wallet_transaction (append-only ledger)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE wallet (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, balance BIGINT DEFAULT 0 NOT NULL, credit_limit BIGINT DEFAULT 0 NOT NULL, currency VARCHAR(8) DEFAULT 'IRT' NOT NULL, status VARCHAR(16) DEFAULT 'active' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, last_activity_at DATETIME DEFAULT NULL, INDEX idx_wallet_last_activity (last_activity_at), UNIQUE INDEX uniq_wallet_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('CREATE TABLE wallet_transaction (id INT AUTO_INCREMENT NOT NULL, wallet_id INT NOT NULL, reversal_of_id INT DEFAULT NULL, created_at DATETIME NOT NULL, type VARCHAR(16) NOT NULL, amount BIGINT NOT NULL, balance_after BIGINT NOT NULL, idempotency_key VARCHAR(128) NOT NULL, created_by_source VARCHAR(16) NOT NULL, created_by_admin_id INT DEFAULT NULL, created_by_label VARCHAR(190) DEFAULT NULL, reference_type VARCHAR(16) DEFAULT NULL, reference_id VARCHAR(64) DEFAULT NULL, note LONGTEXT DEFAULT NULL, INDEX IDX_7DAF972712520F3 (wallet_id), INDEX idx_wallet_transaction_wallet (wallet_id, id), INDEX idx_wallet_transaction_reference (reference_type, reference_id), UNIQUE INDEX uniq_wallet_transaction_key (idempotency_key), UNIQUE INDEX uniq_wallet_transaction_reversal_of (reversal_of_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE wallet ADD CONSTRAINT FK_7C68921FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wallet_transaction ADD CONSTRAINT FK_7DAF972712520F3 FOREIGN KEY (wallet_id) REFERENCES wallet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wallet_transaction ADD CONSTRAINT FK_7DAF97229A0BB4E FOREIGN KEY (reversal_of_id) REFERENCES wallet_transaction (id) ON DELETE CASCADE');
    }

    /**
     * Refused while the ledger has any row, or a wallet has a balance, a credit limit or is frozen: dropping the tables
     * would delete customers' money, its history or an admin's decision. Empty tables (the wallet never used, or only
     * plain active wallets without movements) are dropped as before.
     */
    public function down(Schema $schema): void
    {
        $movements = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM wallet_transaction');
        $this->abortIf($movements > 0, sprintf('Irreversible: the wallet ledger has %d movement(s); dropping wallet / wallet_transaction would delete customers\' balances and their history. Nothing was changed.', $movements));
        // A wallet without movements can still carry an admin's decision: a credit limit or a freeze.
        $withState = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM wallet WHERE balance <> 0 OR credit_limit <> 0 OR status <> 'active'");
        $this->abortIf($withState > 0, sprintf('Irreversible: %d wallet(s) have a balance, a credit limit or are frozen; dropping wallet would lose them. Nothing was changed.', $withState));

        $this->addSql('DROP TABLE wallet_transaction');
        $this->addSql('DROP TABLE wallet');
    }
}
