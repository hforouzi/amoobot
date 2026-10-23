<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Tests\Support\IntegrationTestCase;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
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

    private function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /** Only if the refusal did not hold: rebuild the tables and their RESTRICT foreign keys for the rest of the suite. */
    private function restore(): void
    {
        if (2 === $this->ledgerTables()) {
            return;
        }
        $this->migrate(self::VERSION, 'up');
        $this->migrate('DoctrineMigrations\\Version20261025090100', 'down');
        $this->migrate('DoctrineMigrations\\Version20261025090100', 'up');
    }
}
