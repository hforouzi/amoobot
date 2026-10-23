<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Reseller\Domain\ResellerStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Real races of two purchases by the same reseller (two PHP processes, two connections), repeated because one run only
 * proves one interleaving. Whatever the order: never an overdraft, never over the daily cap, a refused purchase leaves
 * no order behind, one debit per paid order, and the ledger reconciles. A purchase racing an admin's disable either
 * completed before it or was refused.
 */
#[Group('subprocess')]
final class ResellerParallelPurchaseTest extends IntegrationTestCase
{
    use ResellerFixtures;
    use SubprocessAssertions;

    private const PRICE = 120000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
    }

    /**
     * @return iterable<string, array{int, ?int, list<string>}>
     */
    public static function races(): iterable
    {
        foreach (range(1, 3) as $run) {
            yield 'money for one #'.$run => [self::PRICE + 50000, null, ['confirmed', 'wallet_insufficient']];
            yield 'daily cap for one #'.$run => [3 * self::PRICE, self::PRICE + 50000, ['confirmed', 'daily_limit_reached']];
            yield 'room for both #'.$run => [3 * self::PRICE, null, ['confirmed', 'confirmed']];
        }
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('races')]
    public function testTwoPurchasesAtOnce(int $funded, ?int $dailyCap, array $expected): void
    {
        $this->fund($funded);
        $this->reseller->setDailySpendLimit($dailyCap);
        $this->em->flush();

        $outcomes = $this->runInParallel([['reseller-buy.php', (string) $this->user->getId(), (string) $this->plan->getId()], ['reseller-buy.php', (string) $this->user->getId(), (string) $this->plan->getId()]]);
        sort($outcomes);
        sort($expected);

        self::assertSame($expected, $outcomes);
        $paid = count(array_filter($outcomes, static fn (string $o): bool => 'confirmed' === $o));
        self::assertSame($paid, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'a refused purchase leaves no order');
        self::assertSame($paid, $this->sqlInt("SELECT COUNT(*) FROM payment WHERE method = 'wallet' AND status = 'confirmed'"));
        self::assertSame($paid, $this->sqlInt("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'purchase'"));
        self::assertSame($paid, $this->sqlInt('SELECT COUNT(*) FROM vpn_service WHERE sold_by_reseller_id IS NOT NULL'));
        self::assertSame($funded - $paid * self::PRICE, $this->balance());
        self::assertGreaterThanOrEqual(0, $this->balance(), 'no overdraft');
        if (null !== $dailyCap) {
            self::assertLessThanOrEqual($dailyCap, $this->sqlInt('SELECT COALESCE(SUM(amount), 0) FROM `order`'), 'never over the daily cap');
        }
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    /**
     * @return iterable<string, array{}>
     */
    public static function runs(): iterable
    {
        foreach (range(1, 3) as $run) {
            yield '#'.$run => [];
        }
    }

    #[DataProvider('runs')]
    public function testAPurchaseRacingADisableCompletesOrIsRefused(): void
    {
        $this->fund(3 * self::PRICE);

        [$purchase, $disable] = $this->runInParallel([['reseller-buy.php', (string) $this->user->getId(), (string) $this->plan->getId()], ['reseller-disable.php', (string) $this->reseller->getId()]]);

        self::assertSame('disabled', $disable);
        self::assertContains($purchase, ['confirmed', 'reseller_unavailable']);
        $paid = 'confirmed' === $purchase ? 1 : 0;
        self::assertSame($paid, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertSame(3 * self::PRICE - $paid * self::PRICE, $this->balance());
        $this->em->refresh($this->reseller);
        self::assertSame(ResellerStatus::DISABLED, $this->reseller->getStatus());
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    /**
     * @param list<list<string>> $commands script name and arguments, each in its own process
     *
     * @return list<string> what each printed, in the same order
     */
    private function runInParallel(array $commands): array
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        foreach ($commands as $arguments) {
            $script = (string) array_shift($arguments);
            $process = new Process([$php, dirname(__DIR__).'/Support/bin/'.$script, ...$arguments], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => '1000'], null, 60);
            $process->start();
            $processes[] = [$script, $process];
        }
        $outcomes = [];
        foreach ($processes as [$script, $process]) {
            $process->wait();
            $outcomes[] = self::assertSubprocessClean($process, $script);
        }

        return $outcomes;
    }
}
