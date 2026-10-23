<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Tests\Support\IntegrationTestCase;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletStatus;
use App\Wallet\Domain\WalletTransactionType;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Third review, minor: rolling back Version20261024090000 (wallet / wallet_transaction) dropped both tables even when
 * the ledger had rows, i.e. customers' balances and their history. down() now refuses while the ledger has any row and
 * changes nothing.
 */
final class WalletLedgerMigrationTest extends IntegrationTestCase
{
    private const VERSION = 'DoctrineMigrations\\Version20261024090000';
    private const FOREIGN_KEYS = 'DoctrineMigrations\\Version20261025090100';

    /** Runs the migration in its own console process (Doctrine Migrations freezes its configuration after one run). */
    private function migrate(string $version, string $direction): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'bin/console', 'doctrine:migrations:execute', $version, '--'.$direction, '--no-interaction', '--env=test'], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 120);
        $process->run();

        return $process;
    }

    private function ledgerTables(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('wallet', 'wallet_transaction')");
    }

    public function testDownIsRefusedWhileTheLedgerHasRowsAndNothingChanges(): void
    {
        $user = $this->createUser();
        self::getContainer()->get(WalletService::class)->credit($user, 5000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());

        try {
            $down = $this->migrate(self::VERSION, 'down');

            self::assertStringContainsString('Irreversible: the wallet ledger has 1 movement(s)', $down->getOutput().$down->getErrorOutput());
            self::assertSame(2, $this->ledgerTables(), 'nothing was dropped');
            self::assertSame(5000, (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$user->getId()]));
            self::assertSame(1, $this->rows('wallet_transaction'));
            self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [self::VERSION]));
        } finally {
            $this->restore();
        }
    }

    /** @return array<string, string> foreign key name => ON DELETE rule, for the ledger's three keys */
    private function deleteRules(): array
    {
        return $this->em->getConnection()->fetchAllKeyValue("SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('wallet', 'wallet_transaction') ORDER BY CONSTRAINT_NAME");
    }

    private const RESTRICT = ['FK_7C68921FA76ED395' => 'RESTRICT', 'FK_7DAF97229A0BB4E' => 'RESTRICT', 'FK_7DAF972712520F3' => 'RESTRICT'];

    /**
     * Fourth review, item 5: rolling back Version20261025090100 put the ledger's foreign keys back to ON DELETE CASCADE
     * even with money in the ledger (a later customer delete would erase it), and a multi-step rollback did that before
     * Version20261024090000 refused. Now it refuses first, before any key is touched.
     */
    public function testRestrictKeysStayWhileTheLedgerHasRows(): void
    {
        $user = $this->createUser();
        self::getContainer()->get(WalletService::class)->credit($user, 5000, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        self::assertSame(self::RESTRICT, $this->deleteRules());

        try {
            $down = $this->migrate(self::FOREIGN_KEYS, 'down');

            self::assertNotSame(0, $down->getExitCode());
            self::assertStringContainsString('Irreversible: the wallet ledger has 1 movement(s)', $down->getOutput().$down->getErrorOutput());
            self::assertSame(self::RESTRICT, $this->deleteRules(), 'no foreign key was touched');
            self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT version FROM doctrine_migration_versions WHERE version = ?', [self::FOREIGN_KEYS]));

            // A multi-step rollback stops at the same place, before anything is changed.
            $prev = $this->migrateTo('DoctrineMigrations\\Version20261023090000');
            self::assertNotSame(0, $prev->getExitCode());
            self::assertSame(self::RESTRICT, $this->deleteRules());
            self::assertSame(2, $this->ledgerTables());
            self::assertSame(1, $this->rows('wallet_transaction'));
        } finally {
            $this->restore();
        }
    }

    /** A wallet without movements but with an admin's decision on it (frozen, or a credit limit) is not given up either. */
    public function testWalletsWithAFreezeOrACreditLimitAreNeverDroppedOrUnprotected(): void
    {
        $frozen = $this->createUser();
        $credit = $this->createUser();
        $wallets = self::getContainer()->get(WalletService::class);
        $wallets->setStatus($frozen, WalletStatus::FROZEN, WalletActor::system());
        $wallets->setCreditLimit($credit, 90000, WalletActor::system());
        self::assertSame(0, $this->rows('wallet_transaction'));

        try {
            $keys = $this->migrate(self::FOREIGN_KEYS, 'down');
            self::assertStringContainsString('Irreversible: 2 wallet(s) have a balance, a credit limit or are frozen', $keys->getOutput().$keys->getErrorOutput());
            self::assertSame(self::RESTRICT, $this->deleteRules());

            $tables = $this->migrate(self::VERSION, 'down');
            self::assertStringContainsString('Irreversible: 2 wallet(s) have a balance, a credit limit or are frozen', $tables->getOutput().$tables->getErrorOutput());
            self::assertSame(2, $this->ledgerTables(), 'nothing was dropped');
            self::assertSame(2, $this->rows('wallet'));
        } finally {
            $this->restore();
        }
    }

    private function migrateTo(string $version): Process
    {
        $process = new Process([(new PhpExecutableFinder())->find(false) ?: 'php', 'bin/console', 'doctrine:migrations:migrate', $version, '--no-interaction', '--env=test'], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 120);
        $process->run();

        return $process;
    }

    private function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /**
     * Only if the refusal did not hold: rebuild the tables and their RESTRICT foreign keys for the rest of the suite;
     * then every later migration a multi-step rollback reverted before the refusal is applied again.
     */
    private function restore(): void
    {
        if (2 !== $this->ledgerTables()) {
            $this->migrate(self::VERSION, 'up');
            $this->migrate(self::FOREIGN_KEYS, 'down');
            $this->migrate(self::FOREIGN_KEYS, 'up');
        } elseif (self::RESTRICT !== $this->deleteRules()) {
            $this->migrate(self::FOREIGN_KEYS, 'up');
        }
        $this->migrateTo('latest');
    }
}
