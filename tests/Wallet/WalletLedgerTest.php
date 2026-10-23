<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Domain\InsufficientWalletBalance;
use App\Wallet\Domain\WalletException;
use App\Wallet\Domain\WalletFrozen;
use App\Wallet\Domain\WalletIdempotencyConflict;
use App\Wallet\Domain\WalletStatus;
use App\Wallet\Domain\WalletTransactionType;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Part 1: the append-only ledger. WalletService is the only writer; every write locks the wallet row, is keyed by a
 * unique idempotency key, and keeps the cached balance equal to the ledger sum.
 */
final class WalletLedgerTest extends IntegrationTestCase
{
    use SubprocessAssertions;

    private function wallets(): WalletService
    {
        return self::getContainer()->get(WalletService::class);
    }

    private function balanceOf(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$user->getId()]);
    }

    private function ledgerSum(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(SUM(t.amount), 0) FROM wallet_transaction t JOIN wallet w ON w.id = t.wallet_id WHERE w.user_id = ?', [$user->getId()]);
    }

    private function countTransactions(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet_transaction t JOIN wallet w ON w.id = t.wallet_id WHERE w.user_id = ?', [$user->getId()]);
    }

    public function testAWalletIsCreatedLazilyOnFirstUseWithDefaults(): void
    {
        $user = $this->createUser();
        self::assertNull($this->wallets()->find($user), 'no wallet before first use');

        $wallet = $this->wallets()->walletFor($user);
        $again = $this->wallets()->walletFor($user);

        self::assertSame($wallet->getId(), $again->getId());
        self::assertSame(0, $wallet->getBalance());
        self::assertSame(0, $wallet->getCreditLimit());
        self::assertSame(Wallet::CURRENCY, $wallet->getCurrency());
        self::assertSame(WalletStatus::ACTIVE, $wallet->getStatus());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM wallet WHERE user_id = ?', [$user->getId()]));
    }

    public function testCreditAndDebitAreRecordedWithTheBalanceAfterEachMovement(): void
    {
        $user = $this->createUser();

        $credit = $this->wallets()->credit($user, 50000, WalletTransactionType::TOPUP, 'test:credit:1', WalletActor::system(), 'payment', '17', 'top-up');
        $debit = $this->wallets()->debit($user, 20000, WalletTransactionType::PURCHASE, 'test:debit:1', WalletActor::system(), 'payment', '18');

        self::assertSame(50000, $credit->getAmount());
        self::assertSame(50000, $credit->getBalanceAfter());
        self::assertSame(-20000, $debit->getAmount(), 'debits are stored negative');
        self::assertSame(30000, $debit->getBalanceAfter());
        self::assertSame('payment', $credit->getReferenceType());
        self::assertSame('17', $credit->getReferenceId());
        self::assertSame(WalletActor::SOURCE_SYSTEM, $credit->getCreatedBySource());
        self::assertNull($credit->getCreatedByAdminId());
        self::assertSame(30000, $this->balanceOf($user));
        self::assertSame(30000, $this->ledgerSum($user));
    }

    public function testARetryWithTheSameKeyNeverAppliesTwice(): void
    {
        $user = $this->createUser();

        $first = $this->wallets()->credit($user, 10000, WalletTransactionType::TOPUP, 'topup:payment:1', WalletActor::system());
        $second = $this->wallets()->credit($user, 10000, WalletTransactionType::TOPUP, 'topup:payment:1', WalletActor::system());
        $debit1 = $this->wallets()->debit($user, 4000, WalletTransactionType::PURCHASE, 'purchase:payment:2', WalletActor::system());
        $debit2 = $this->wallets()->debit($user, 4000, WalletTransactionType::PURCHASE, 'purchase:payment:2', WalletActor::system());

        self::assertSame($first->getId(), $second->getId());
        self::assertSame($debit1->getId(), $debit2->getId());
        self::assertSame(2, $this->countTransactions($user));
        self::assertSame(6000, $this->balanceOf($user));
    }

    public function testTheSameKeyForADifferentMovementIsAConflictAndChangesNothing(): void
    {
        $user = $this->createUser();
        $this->wallets()->credit($user, 10000, WalletTransactionType::TOPUP, 'topup:payment:9', WalletActor::system());

        foreach ([
            fn () => $this->wallets()->credit($user, 9999, WalletTransactionType::TOPUP, 'topup:payment:9', WalletActor::system()),
            fn () => $this->wallets()->credit($user, 10000, WalletTransactionType::REWARD, 'topup:payment:9', WalletActor::system()),
            fn () => $this->wallets()->debit($user, 10000, WalletTransactionType::PURCHASE, 'topup:payment:9', WalletActor::system()),
            fn () => $this->wallets()->credit($this->createUser(), 10000, WalletTransactionType::TOPUP, 'topup:payment:9', WalletActor::system()),
        ] as $i => $call) {
            try {
                $call();
                self::fail('conflict expected for case '.$i);
            } catch (WalletIdempotencyConflict) {
            }
        }

        self::assertSame(1, $this->countTransactions($user));
        self::assertSame(10000, $this->balanceOf($user));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'large negative' => [-50000];
        yield 'above the maximum' => [WalletService::MAX_AMOUNT + 1];
    }

    #[DataProvider('invalidAmounts')]
    public function testOnlyPositiveAmountsAreAccepted(int $amount): void
    {
        $user = $this->createUser();
        $this->wallets()->credit($user, 1000, WalletTransactionType::TOPUP, 'seed', WalletActor::system());

        foreach (['credit', 'debit'] as $method) {
            try {
                $this->wallets()->{$method}($user, $amount, WalletTransactionType::ADJUSTMENT, 'bad:'.$method, WalletActor::system());
                self::fail($method.' accepted '.$amount);
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame(1000, $this->balanceOf($user));
        self::assertSame(1, $this->countTransactions($user));
    }

    public function testAnInvalidTypeOrKeyIsRefused(): void
    {
        $user = $this->createUser();

        foreach ([
            fn () => $this->wallets()->credit($user, 1, 'bonus', 'k1', WalletActor::system()),
            fn () => $this->wallets()->credit($user, 1, WalletTransactionType::REVERSAL, 'k2', WalletActor::system()),
            fn () => $this->wallets()->credit($user, 1, WalletTransactionType::TOPUP, '', WalletActor::system()),
            fn () => $this->wallets()->credit($user, 1, WalletTransactionType::TOPUP, str_repeat('k', 129), WalletActor::system()),
        ] as $call) {
            try {
                $call();
                self::fail('invalid input accepted');
            } catch (\InvalidArgumentException) {
            }
        }

        self::assertSame(0, $this->countTransactions($user));
    }

    public function testADebitBeyondTheBalanceIsRefused(): void
    {
        $user = $this->createUser();
        $this->wallets()->credit($user, 5000, WalletTransactionType::TOPUP, 'seed', WalletActor::system());

        try {
            $this->wallets()->debit($user, 5001, WalletTransactionType::PURCHASE, 'too-much', WalletActor::system());
            self::fail('overdraft accepted');
        } catch (InsufficientWalletBalance $e) {
            self::assertSame('wallet_insufficient', $e->error);
        }

        $this->wallets()->debit($user, 5000, WalletTransactionType::PURCHASE, 'exact', WalletActor::system());
        self::assertSame(0, $this->balanceOf($user));
        self::assertNull($this->wallets()->findTransaction('too-much'), 'a refused debit leaves no row');
    }

    public function testTheCreditLimitBoundary(): void
    {
        $user = $this->createUser();
        $this->wallets()->setCreditLimit($user, 10000, WalletActor::system());
        $this->wallets()->credit($user, 2000, WalletTransactionType::TOPUP, 'seed', WalletActor::system());

        // balance + credit limit = 12000: exactly that is allowed, one Toman more is not.
        try {
            $this->wallets()->debit($user, 12001, WalletTransactionType::PURCHASE, 'over', WalletActor::system());
            self::fail('debit beyond the credit limit accepted');
        } catch (InsufficientWalletBalance) {
        }
        $tx = $this->wallets()->debit($user, 12000, WalletTransactionType::PURCHASE, 'exact', WalletActor::system());

        self::assertSame(-10000, $tx->getBalanceAfter());
        self::assertSame(-10000, $this->balanceOf($user));
        try {
            $this->wallets()->debit($user, 1, WalletTransactionType::PURCHASE, 'one-more', WalletActor::system());
            self::fail('debit beyond the credit limit accepted');
        } catch (InsufficientWalletBalance) {
        }

        // The limit cannot be lowered below what is already used, nor be negative.
        foreach ([9999, -1] as $limit) {
            try {
                $this->wallets()->setCreditLimit($user, $limit, WalletActor::system());
                self::fail('credit limit '.$limit.' accepted');
            } catch (WalletException|\InvalidArgumentException) {
            }
        }
        self::assertSame(10000, (int) $this->em->getConnection()->fetchOne('SELECT credit_limit FROM wallet WHERE user_id = ?', [$user->getId()]));
    }

    public function testAReversalIsAppliedOnceAndRestoresTheBalance(): void
    {
        $user = $this->createUser();
        $this->wallets()->credit($user, 30000, WalletTransactionType::TOPUP, 'seed', WalletActor::system());
        $debit = $this->wallets()->debit($user, 12000, WalletTransactionType::PURCHASE, 'purchase:payment:5', WalletActor::system(), 'payment', '5');

        $reversal = $this->wallets()->reverse('purchase:payment:5', 'reversal:payment:5', WalletActor::system(), 'refund');
        $again = $this->wallets()->reverse('purchase:payment:5', 'reversal:payment:5', WalletActor::system(), 'refund');
        $otherKey = $this->wallets()->reverse('purchase:payment:5', 'reversal:payment:5:b', WalletActor::system(), 'refund');

        self::assertInstanceOf(WalletTransaction::class, $reversal);
        self::assertSame(WalletTransactionType::REVERSAL, $reversal->getType());
        self::assertSame(12000, $reversal->getAmount());
        self::assertSame($debit->getId(), $reversal->getReversalOf()?->getId());
        self::assertSame($reversal->getId(), $again?->getId());
        self::assertSame($reversal->getId(), $otherKey?->getId(), 'a movement is reversed at most once, whatever the key');
        self::assertSame(30000, $this->balanceOf($user));
        self::assertSame(3, $this->countTransactions($user));
        self::assertNull($this->wallets()->reverse('no-such-key', 'reversal:none', WalletActor::system()), 'nothing to reverse');

        try {
            $this->wallets()->reverse('reversal:payment:5', 'reversal:of:reversal', WalletActor::system());
            self::fail('a reversal was reversed');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testAFrozenWalletRefusesSpendingButStillReceivesMoney(): void
    {
        $user = $this->createUser();
        $this->wallets()->credit($user, 10000, WalletTransactionType::TOPUP, 'seed', WalletActor::system());
        $this->wallets()->setStatus($user, WalletStatus::FROZEN, WalletActor::system());

        try {
            $this->wallets()->debit($user, 1000, WalletTransactionType::PURCHASE, 'frozen-buy', WalletActor::system());
            self::fail('a frozen wallet paid');
        } catch (WalletFrozen $e) {
            self::assertSame('wallet_frozen', $e->error);
        }
        // Money already paid at a gateway is never lost; an admin can still correct.
        $this->wallets()->credit($user, 5000, WalletTransactionType::TOPUP, 'topup-while-frozen', WalletActor::system());
        $this->wallets()->debit($user, 2000, WalletTransactionType::ADJUSTMENT, 'admin-fix', WalletActor::system());

        self::assertSame(13000, $this->balanceOf($user));
        $this->wallets()->setStatus($user, WalletStatus::ACTIVE, WalletActor::system());
        $this->wallets()->debit($user, 1000, WalletTransactionType::PURCHASE, 'after-unfreeze', WalletActor::system());
        self::assertSame(12000, $this->balanceOf($user));
    }

    public function testLedgerRowsCannotBeChangedOrDeleted(): void
    {
        $user = $this->createUser();
        $tx = $this->wallets()->credit($user, 1000, WalletTransactionType::TOPUP, 'immutable', WalletActor::system());

        try {
            $this->em->remove($tx);
            $this->em->flush();
            self::fail('a ledger row was deleted');
        } catch (\LogicException $e) {
            self::assertStringContainsString('append-only', $e->getMessage());
        }

        self::assertFalse(method_exists(WalletTransaction::class, 'setAmount'), 'no setters on the ledger row');
        self::assertSame(1, $this->countTransactions($user));
    }

    public function testTheCachedBalanceCannotBeWrittenOutsideTheService(): void
    {
        foreach (get_class_methods(Wallet::class) as $method) {
            self::assertNotSame('setBalance', $method);
        }

        $user = $this->createUser();
        $wallet = $this->wallets()->walletFor($user);
        $this->wallets()->credit($user, 700, WalletTransactionType::TOPUP, 'k', WalletActor::system());
        $this->em->refresh($wallet);

        self::assertSame(700, $wallet->getBalance());
        self::assertNotNull($wallet->getLastActivityAt());
    }

    public function testReconcileReportsATamperedBalanceAndNeverFixesIt(): void
    {
        $honest = $this->createUser();
        $tampered = $this->createUser();
        $this->wallets()->credit($honest, 1000, WalletTransactionType::TOPUP, 'h', WalletActor::system());
        $this->wallets()->credit($tampered, 1000, WalletTransactionType::TOPUP, 't', WalletActor::system());
        $tamperedWalletId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM wallet WHERE user_id = ?', [$tampered->getId()]);

        $clean = $this->reconcile();
        self::assertSame(Command::SUCCESS, $clean->getStatusCode(), $clean->getDisplay());

        $this->em->getConnection()->executeStatement('UPDATE wallet SET balance = balance + 500 WHERE id = ?', [$tamperedWalletId]);
        $tester = $this->reconcile();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('#'.$tamperedWalletId, $display);
        self::assertStringContainsString('1500', $display);
        self::assertStringContainsString('1000', $display);
        self::assertSame(1500, $this->balanceOf($tampered), 'reported, never auto-fixed');
        self::assertSame(1000, $this->balanceOf($honest));
    }

    public function testReconcileAlsoCatchesABrokenBalanceAfterChain(): void
    {
        $user = $this->createUser();
        $this->wallets()->credit($user, 1000, WalletTransactionType::TOPUP, 'a', WalletActor::system());
        $this->wallets()->credit($user, 1000, WalletTransactionType::TOPUP, 'b', WalletActor::system());
        // Sum and cached balance still agree, but one row's running balance is wrong.
        $this->em->getConnection()->executeStatement("UPDATE wallet_transaction SET balance_after = 999 WHERE idempotency_key = 'a'");

        $tester = $this->reconcile();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('balance_after', $tester->getDisplay());
    }

    #[Group('subprocess')]
    public function testConcurrentDebitsCannotOverdraw(): void
    {
        $user = $this->createUser();
        $this->wallets()->credit($user, 1000, WalletTransactionType::TOPUP, 'seed', WalletActor::system());

        $outcomes = $this->runParallel([
            [(string) $user->getId(), '700', 'parallel-a', '1500'],
            [(string) $user->getId(), '700', 'parallel-b', '1500'],
        ]);
        sort($outcomes);

        self::assertSame(['ok', 'wallet_insufficient'], $outcomes);
        self::assertSame(300, $this->balanceOf($user));
        self::assertSame(300, $this->ledgerSum($user));
        self::assertSame(2, $this->countTransactions($user));
    }

    #[Group('subprocess')]
    public function testConcurrentRetriesWithOneKeyApplyOnce(): void
    {
        $user = $this->createUser();
        $this->wallets()->credit($user, 1000, WalletTransactionType::TOPUP, 'seed', WalletActor::system());

        $outcomes = $this->runParallel([
            [(string) $user->getId(), '300', 'same-key', '1500'],
            [(string) $user->getId(), '300', 'same-key', '1500'],
        ]);

        self::assertSame(['ok', 'ok'], $outcomes);
        self::assertSame(700, $this->balanceOf($user));
        self::assertSame(2, $this->countTransactions($user));
    }

    /**
     * A caller's outer transaction took its snapshot before another process committed the same movement. The ledger
     * row is then invisible to a plain read, yet it exists: the write returns that committed row (an idempotent retry)
     * instead of failing on the unique key, and nothing is written twice.
     */
    #[Group('subprocess')]
    public function testAMovementCommittedAfterTheCallersSnapshotIsReturnedNotWrittenAgain(): void
    {
        $user = $this->createUser();
        $this->wallets()->credit($user, 1000, WalletTransactionType::TOPUP, 'test:seed:snapshot', WalletActor::system());
        $connection = $this->em->getConnection();

        $connection->beginTransaction();
        try {
            $connection->fetchOne('SELECT COUNT(*) FROM wallet_transaction'); // the outer transaction's snapshot starts here
            self::assertSame(['ok'], $this->runParallel([[(string) $user->getId(), '300', 'test:debit:snapshot', '0']]));
            self::assertFalse($connection->fetchOne('SELECT id FROM wallet_transaction WHERE idempotency_key = ?', ['test:debit:snapshot']), 'invisible to the snapshot');

            $again = $this->wallets()->debit($user, 300, WalletTransactionType::PURCHASE, 'test:debit:snapshot', WalletActor::system());
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        self::assertSame(-300, $again->getAmount());
        self::assertSame(700, $again->getBalanceAfter(), 'the row the other process wrote');
        self::assertSame(700, $this->balanceOf($user));
        self::assertSame(2, $this->countTransactions($user));
        self::assertSame($this->ledgerSum($user), $this->balanceOf($user));
    }

    /**
     * @param list<list<string>> $argumentLists
     *
     * @return list<string>
     */
    private function runParallel(array $argumentLists): array
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        foreach ($argumentLists as $arguments) {
            $process = new Process([$php, dirname(__DIR__).'/Support/bin/wallet-debit.php', ...$arguments], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 60);
            $process->start();
            $processes[] = $process;
        }
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            $outcomes[] = self::assertSubprocessClean($process, 'wallet-debit.php');
        }

        return $outcomes;
    }

    private function reconcile(): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:wallet:reconcile'));
        $tester->execute([]);

        return $tester;
    }
}
