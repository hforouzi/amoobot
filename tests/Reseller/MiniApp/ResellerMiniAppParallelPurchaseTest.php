<?php

declare(strict_types=1);

namespace App\Tests\Reseller\MiniApp;

use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A double submit of the Mini App purchase in two real processes (two connections) at the same time, repeated because
 * one run proves one interleaving. With the same Idempotency-Key, or with two keys but the same confirmation (a double
 * tap after an error dropped the key), it is one order, one debit, one service; the other call answers with that
 * purchase's state or "in progress", never a second order. The panel is slowed down (1 s) so the calls overlap.
 */
#[Group('subprocess')]
final class ResellerMiniAppParallelPurchaseTest extends IntegrationTestCase
{
    use ResellerFixtures;
    use SubprocessAssertions;

    private const PRICE = 120000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->em->getConnection()->executeStatement('DELETE FROM checkout_request');
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->fund(1_000_000);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function runs(): iterable
    {
        foreach (range(1, 3) as $run) {
            yield 'same key #'.$run => [true];
            yield 'two keys, same confirmation #'.$run => [false];
        }
    }

    #[DataProvider('runs')]
    public function testADoubleSubmitIsOnePurchase(bool $sameKey): void
    {
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        $token = $confirmation->amount.'.'.$confirmation->nonce;
        $key = bin2hex(random_bytes(16));
        $call = fn (string $key): array => ['reseller-miniapp-buy.php', self::CUSTOMER, (string) $this->plan->getId(), $key, $token];

        $answers = $this->runInParallel([$call($key), $call($sameKey ? $key : bin2hex(random_bytes(16)))]);

        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM `order`'), json_encode($answers) ?: '');
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'purchase'"));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM vpn_service WHERE sold_by_reseller_id IS NOT NULL'));
        self::assertSame(1_000_000 - self::PRICE, $this->balance());
        foreach ($answers as [$status, $body]) {
            self::assertContains($status, [200, 409], json_encode($body) ?: '');
            if (409 === $status) {
                self::assertSame('idempotency_in_progress', $body['error'] ?? null);
            } else {
                self::assertArrayHasKey('redirect', $body, json_encode($body) ?: '');
            }
        }
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    /**
     * @param list<list<string>> $commands
     *
     * @return list<array{int, array<string, mixed>}>
     */
    private function runInParallel(array $commands): array
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        foreach ($commands as $arguments) {
            $script = (string) array_shift($arguments);
            $process = new Process([$php, dirname(__DIR__, 2).'/Support/bin/'.$script, ...$arguments], dirname(__DIR__, 3), ['APP_ENV' => 'test', 'TEST_PANEL_DELAY_MS' => '1000'], null, 60);
            $process->start();
            $processes[] = [$script, $process];
        }
        $answers = [];
        foreach ($processes as [$script, $process]) {
            $process->wait();
            $answer = json_decode(self::assertSubprocessClean($process, $script), true);
            self::assertIsArray($answer);
            $answers[] = [(int) $answer[0], (array) $answer[1]];
        }

        return $answers;
    }
}
