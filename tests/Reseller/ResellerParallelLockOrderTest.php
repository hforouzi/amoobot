<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Reseller\Domain\ResellerException;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Ten purchases by the same reseller at the same moment (ten PHP processes, ten connections, released together),
 * repeated: new services, renewals and extra traffic of services he sold, while the earlier ones are still being
 * provisioned. The purchase locks the user row before the reseller row, the order provisioning's foreign key checks
 * take them in (ResellerCheckout's class comment), so none of them is a deadlock victim: no purchase fails, no order
 * fails provisioning, the balance is exact and the ledger reconciles.
 */
#[Group('subprocess')]
final class ResellerParallelLockOrderTest extends IntegrationTestCase
{
    use ResellerFixtures;
    use SubprocessAssertions;

    private const PROCESSES = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->setting('traffic_addon.enabled', 'true');
        $this->setting('traffic_addon.min_gb', '1');
        $this->setting('traffic_addon.max_gb', '100');
        $this->setting('traffic_addon.price_per_gb', '3000');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function runs(): iterable
    {
        foreach (range(1, 3) as $run) {
            yield 'new services #'.$run => ['new'];
            yield 'new, renewals and extra traffic #'.$run => ['mixed'];
        }
    }

    #[DataProvider('runs')]
    public function testTenPurchasesAtOnceAllGoThrough(string $mix): void
    {
        $this->fund(10_000_000);
        $sold = [];
        if ('mixed' === $mix) {
            foreach (range(1, 6) as $ignored) {
                $this->buy();
            }
            $sold = array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT id FROM vpn_service WHERE sold_by_reseller_id IS NOT NULL ORDER BY id'));
        }
        $before = $this->balance();
        $ordersBefore = $this->sqlInt('SELECT COUNT(*) FROM `order`');
        $lastOrder = $this->sqlInt('SELECT COALESCE(MAX(id), 0) FROM `order`');

        $commands = [];
        foreach (range(0, self::PROCESSES - 1) as $i) {
            $commands[] = match (true) {
                'new' === $mix || $i < 4 => ['new', (string) $this->plan->getId()],
                $i < 7 => ['renewal', (string) $sold[$i - 4]],
                default => ['add_traffic', (string) $sold[$i - 4], '10'],
            };
        }
        $outcomes = $this->runTogether($commands);

        self::assertSame(array_fill(0, self::PROCESSES, 'confirmed'), $outcomes);
        self::assertSame($ordersBefore + self::PROCESSES, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM `order` WHERE status = 'provisioning_failed'"));
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM payment WHERE status = 'provisioning_failed'"));
        $paid = (int) $this->em->getConnection()->fetchOne('SELECT SUM(amount) FROM `order` WHERE id > ?', [$lastOrder]);
        self::assertSame(4 * 120000 + ('new' === $mix ? 6 * 120000 : 3 * 120000 + 3 * 24000), $paid);
        self::assertSame($before - $paid, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    /**
     * @return iterable<string, array{}>
     */
    public static function repeats(): iterable
    {
        foreach (range(1, 3) as $run) {
            yield '#'.$run => [];
        }
    }

    #[DataProvider('repeats')]
    public function testMoneyForThreeOfTenRefusesTheOthersCleanly(): void
    {
        $this->fund(3 * 120000 + 50000);

        $outcomes = $this->runTogether(array_fill(0, self::PROCESSES, ['new', (string) $this->plan->getId()]));
        sort($outcomes);

        self::assertSame([...array_fill(0, 3, 'confirmed'), ...array_fill(0, 7, 'wallet_insufficient')], $outcomes);
        self::assertSame(3, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'a refused purchase leaves no order');
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM `order` WHERE status = 'provisioning_failed'"));
        self::assertSame(50000, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    /**
     * A purchase that waits too long for a lock (here: the reseller's user row, held by another connection) is rolled
     * back and tried again; held past ResellerCheckout::LOCK_ATTEMPTS it is refused as "busy" with nothing written.
     *
     * @return iterable<string, array{int, string, int}>
     */
    public static function heldLocks(): iterable
    {
        yield 'released during the second attempt' => [1500, 'confirmed', 1];
        yield 'held past every attempt' => [8000, 'busy', 0];
    }

    #[DataProvider('heldLocks')]
    public function testALockWaitIsRetriedThenBusy(int $heldMs, string $expected, int $orders): void
    {
        $this->fund(1_000_000);
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        $connection = $this->em->getConnection();
        $ready = sys_get_temp_dir().'/amoobot-held-lock-'.bin2hex(random_bytes(6));
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $holder = new Process([$php, dirname(__DIR__).'/Support/bin/hold-row-lock.php', $ready, 'user', (string) $this->user->getId(), (string) $heldMs], dirname(__DIR__, 2), ['APP_ENV' => 'test'], null, 60);
        $holder->start();
        $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $until = microtime(true) + 60;
            while (!is_file($ready) && $holder->isRunning() && microtime(true) < $until) {
                usleep(20_000);
            }
            self::assertFileExists($ready, 'the lock is held: '.$holder->getErrorOutput());
            try {
                $outcome = $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), $confirmation)->outcome->value;
            } catch (ResellerException $e) {
                $outcome = $e->error;
            }
        } finally {
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            $holder->wait();
            is_file($ready) && unlink($ready);
        }

        self::assertSame('released', self::assertSubprocessClean($holder, 'hold-row-lock.php'));
        self::assertSame($expected, $outcome);
        self::assertSame($orders, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'a refused purchase leaves no order');
        self::assertSame(1_000_000 - $orders * 120000, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    /**
     * Starts every purchase in its own process, lets them all boot and quote, then releases them at once.
     *
     * @param list<list<string>> $commands kind, target and (add-traffic) GB of each purchase
     *
     * @return list<string> what each printed, in the same order
     */
    private function runTogether(array $commands): array
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $dir = sys_get_temp_dir().'/amoobot-reseller-together-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        try {
            foreach ($commands as $arguments) {
                $process = new Process([$php, dirname(__DIR__).'/Support/bin/reseller-buy-together.php', $dir, (string) $this->user->getId(), ...$arguments], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => '300'], null, 180);
                $process->start();
                $processes[] = $process;
            }
            $until = microtime(true) + 150;
            while (count(glob($dir.'/ready-*') ?: []) < count($processes) && microtime(true) < $until) {
                foreach ($processes as $process) {
                    if (!$process->isRunning()) {
                        self::fail('A purchase process ended before the start: '.$process->getOutput().$process->getErrorOutput());
                    }
                }
                usleep(20_000);
            }
            touch($dir.'/go');
            $outcomes = [];
            // Every process finishes before anything is asserted: a failed assertion must not leave one still writing.
            foreach ($processes as $process) {
                $process->wait();
            }
            foreach ($processes as $process) {
                $outcomes[] = self::assertSubprocessClean($process, 'reseller-buy-together.php');
            }

            return $outcomes;
        } finally {
            foreach ($processes as $process) {
                $process->isRunning() && $process->stop(0);
            }
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }
}
