<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Tests\Support\IntegrationTestCase;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\WalletTransactionType;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;

/**
 * The ledger is financial history: the database refuses to delete a customer who has a wallet, a wallet with ledger
 * rows, or a ledger row that was reversed (ON DELETE RESTRICT; it used to cascade and erase the ledger silently).
 */
final class WalletLedgerForeignKeysTest extends IntegrationTestCase
{
    public function testTheLedgerForeignKeysRestrictDeletes(): void
    {
        $rules = $this->em->getConnection()->fetchAllKeyValue(
            "SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('wallet', 'wallet_transaction') ORDER BY CONSTRAINT_NAME"
        );

        self::assertSame(['FK_7C68921FA76ED395' => 'RESTRICT', 'FK_7DAF97229A0BB4E' => 'RESTRICT', 'FK_7DAF972712520F3' => 'RESTRICT'], $rules);
    }

    public function testACustomerWithAWalletAWalletWithRowsAndAReversedRowCannotBeDeleted(): void
    {
        $user = $this->createUser();
        $wallets = self::getContainer()->get(WalletService::class);
        $credit = $wallets->credit($user, 5000, WalletTransactionType::TOPUP, 'test:fk:credit', WalletActor::system());
        $wallets->reverse('test:fk:credit', 'test:fk:reversal', WalletActor::system());
        $connection = $this->em->getConnection();
        $walletId = (int) $connection->fetchOne('SELECT id FROM wallet WHERE user_id = ?', [$user->getId()]);

        foreach ([
            'customer' => fn () => $connection->executeStatement('DELETE FROM `user` WHERE id = ?', [$user->getId()]),
            'wallet' => fn () => $connection->executeStatement('DELETE FROM wallet WHERE id = ?', [$walletId]),
            'reversed row' => fn () => $connection->executeStatement('DELETE FROM wallet_transaction WHERE id = ?', [$credit->getId()]),
        ] as $what => $delete) {
            try {
                $delete();
                self::fail('deleting the '.$what.' must be refused');
            } catch (ForeignKeyConstraintViolationException) {
            }
        }

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM wallet_transaction WHERE wallet_id = ?', [$walletId]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT balance FROM wallet WHERE id = ?', [$walletId]));
    }
}
