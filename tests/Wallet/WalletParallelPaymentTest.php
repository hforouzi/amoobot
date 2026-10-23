<?php

declare(strict_types=1);

namespace App\Tests\Wallet;

use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletActor;
use App\Wallet\Application\WalletService;
use App\Wallet\Application\WalletSettings;
use App\Wallet\Domain\WalletTransactionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Repeated real races of two wallet payments (two PHP processes, two connections). Whatever the interleaving, the money
 * invariants hold: one debit per paid order, never an overdraft, one payment per order, a ledger that sums to the
 * balance. Repeated because a single run only proves one interleaving; and it guards the subprocess harness, which
 * used to fail at random on Windows (concurrent Doctrine proxy writes, see tests/Support/bin/boot-test-kernel.php).
 */
#[Group('subprocess')]
final class WalletParallelPaymentTest extends IntegrationTestCase
{
    use SubprocessAssertions;
    use MiniAppShopFixtures;

    private const PRICE = 150000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'wallet.%'");
        $this->setting(WalletSettings::ENABLED, 'true');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function races(): iterable
    {
        foreach (range(1, 3) as $run) {
            yield 'one order twice #'.$run => [true];
            yield 'two orders, money for one #'.$run => [false];
        }
    }

    #[DataProvider('races')]
    public function testTheMoneyInvariantsHoldWhateverTheInterleaving(bool $sameOrder): void
    {
        $funded = $sameOrder ? 2 * self::PRICE + 100000 : self::PRICE + 50000;
        self::getContainer()->get(WalletService::class)->credit($this->user, $funded, WalletTransactionType::TOPUP, 'seed:'.random_int(1, PHP_INT_MAX), WalletActor::system());
        $first = $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);
        $second = $sameOrder ? $first : $this->createOrder($this->user, $this->plan, OrderType::NEW_SERVICE, self::PRICE);

        $outcomes = $this->payInParallel([(int) $first->getId(), (int) $second->getId()]);
        sort($outcomes);

        $connection = $this->em->getConnection();
        if ($sameOrder) {
            // The loser either waits on the order lock and finds it paid, or reuses the payment and finds it settled.
            self::assertContains('confirmed', $outcomes);
            self::assertContains(array_values(array_diff($outcomes, ['confirmed']))[0] ?? 'confirmed', ['already_processed', 'order_not_payable'], implode(', ', $outcomes));
        } else {
            self::assertSame(['confirmed', 'wallet_insufficient'], $outcomes);
        }
        self::assertSame($funded - self::PRICE, (int) $connection->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]), 'exactly one debit');
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'purchase'"));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment'));
        self::assertSame((int) $connection->fetchOne('SELECT balance FROM wallet WHERE user_id = ?', [$this->user->getId()]), (int) $connection->fetchOne('SELECT SUM(amount) FROM wallet_transaction'));
    }

    /**
     * @param list<int> $orderIds
     *
     * @return list<string>
     */
    private function payInParallel(array $orderIds): array
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        foreach ($orderIds as $orderId) {
            $process = new Process([$php, dirname(__DIR__).'/Support/bin/wallet-pay.php', (string) $orderId], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => '1000'], null, 60);
            $process->start();
            $processes[] = $process;
        }
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            $outcomes[] = self::assertSubprocessClean($process, 'wallet-pay.php');
        }

        return $outcomes;
    }
}
