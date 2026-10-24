<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Entity\Order;
use App\Payment\Application\Settlement\SettlementOutcome;
use App\Reseller\Application\ResellerConfirmations;
use App\Reseller\Domain\ResellerConfirmation;
use App\Reseller\Domain\ResellerException;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * S1 review B1 / B2: every reseller purchase is paid with a one-time confirmation of the price he was shown.
 * - The same confirmation delivered twice (a double tap while the first is still running, Telegram redelivering the
 *   update) buys once: one order, one debit; the second delivery is answered with where the first one stands.
 * - A price that changed since the confirmation is refused (price_changed, with the price now); the client's amount is
 *   only compared, never charged. An old confirmation expires; a forged or altered one is refused.
 */
final class ResellerConfirmationTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ResellerFixtures;
    use SubprocessAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->fund(1000000);
        $this->setting('traffic_addon.enabled', 'true');
        $this->setting('traffic_addon.min_gb', '1');
        $this->setting('traffic_addon.max_gb', '100');
        $this->setting('traffic_addon.price_per_gb', '3000');
        $this->http->reset();
        $this->answerGateways();
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

    /** Two deliveries of one confirmation at the same moment (two processes, two connections, a slow panel). */
    #[DataProvider('runs')]
    #[Group('subprocess')]
    public function testTheSameConfirmationTwiceAtOnceBuysOnce(): void
    {
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        $arguments = ['reseller-buy.php', (string) $this->user->getId(), (string) $this->plan->getId(), (string) $confirmation->amount, $confirmation->nonce];

        $outcomes = $this->parallel([$arguments, $arguments]);
        sort($outcomes);

        self::assertSame(['already_processed', 'confirmed'], $outcomes);
        $this->assertBoughtOnce(120000);
    }

    public function testTheSameConfirmationAgainLaterBuysNothingMore(): void
    {
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        self::assertSame(SettlementOutcome::CONFIRMED, $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), $confirmation)->outcome);
        $order = $this->em->getRepository(Order::class)->findOneBy([]) ?? self::fail();
        self::assertSame($confirmation->nonce, $order->getResellerNonce());

        $this->assertRefused('already_processed', fn () => $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), $confirmation), $order->getId());
        $this->assertBoughtOnce(120000);

        // Still recognised after the confirmation expired: a late replay is "already processed", never a new purchase.
        $expired = $this->confirmationsAt(new \DateTimeImmutable('-1 hour'))->issue($this->reseller, ResellerConfirmations::KIND_NEW, (int) $this->plan->getId(), 0, 0, 120000);
        $this->em->getConnection()->executeStatement('UPDATE `order` SET reseller_nonce = ? WHERE id = ?', [$expired->nonce, $order->getId()]);
        $this->assertRefused('already_processed', fn () => $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), $expired), $order->getId());
        $this->assertBoughtOnce(120000);

        $serviceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service');
        [, , , $renewal] = $this->checkout()->quoteRenewal($this->user, $serviceId);
        $this->checkout()->renew($this->user, $serviceId, $renewal);
        $this->assertRefused('already_processed', fn () => $this->checkout()->renew($this->user, $serviceId, $renewal));
        [, , , $traffic] = $this->checkout()->quoteAddTraffic($this->user, $serviceId, 10);
        $this->checkout()->addTraffic($this->user, $serviceId, 10, $traffic);
        $this->assertRefused('already_processed', fn () => $this->checkout()->addTraffic($this->user, $serviceId, 10, $traffic));

        self::assertSame(3, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertSame(1000000 - 120000 - 120000 - 24000, $this->balance());
    }

    /** The bot: Telegram delivering the same pay button twice. */
    public function testTheBotsPayButtonDeliveredTwice(): void
    {
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$this->plan->getId());
        $pay = $this->sentCallback('rs_buy:');
        self::assertMatchesRegularExpression('/^rs_buy:\d+:120000:[0-9a-z]{7}[0-9a-f]{6}[A-Za-z0-9_-]{10}$/', $pay);

        $this->pressButton(self::CUSTOMER, $pay);
        $this->http->reset();
        $this->pressButton(self::CUSTOMER, $pay);

        $this->assertBoughtOnce(120000);
        $tracking = (string) $this->em->getConnection()->fetchOne('SELECT tracking_code FROM `order`');
        self::assertTrue($this->sentTextContains($this->botText('reseller.already_done', ['order' => ['trackingCode' => $tracking]])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function flows(): iterable
    {
        yield 'new service' => ['new'];
        yield 'renewal' => ['renewal'];
        yield 'extra traffic' => ['traffic'];
    }

    #[DataProvider('flows')]
    public function testAChangedPriceIsRefusedWithThePriceNow(string $flow): void
    {
        $this->buy();
        $serviceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service');
        $balance = $this->balance();
        $orders = $this->sqlInt('SELECT COUNT(*) FROM `order`');
        [$quote, $pay] = match ($flow) {
            'new' => [fn () => $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId()), fn (ResellerConfirmation $c) => $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), $c)],
            'renewal' => [fn () => $this->checkout()->quoteRenewal($this->user, $serviceId), fn (ResellerConfirmation $c) => $this->checkout()->renew($this->user, $serviceId, $c)],
            default => [fn () => $this->checkout()->quoteAddTraffic($this->user, $serviceId, 10), fn (ResellerConfirmation $c) => $this->checkout()->addTraffic($this->user, $serviceId, 10, $c)],
        };
        [, , $shown, $confirmation] = $quote();

        $this->reseller->setDiscountBp(1000);
        $this->em->flush();
        try {
            $pay($confirmation);
            self::fail('charged a price that was not confirmed');
        } catch (ResellerException $e) {
            self::assertSame('price_changed', $e->error);
            self::assertGreaterThan($shown->wholesaleAmount, $e->quote?->wholesaleAmount);
        }
        self::assertSame($orders, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'nothing was written');
        self::assertSame($balance, $this->balance());

        // A forged amount (the price now, but not what this nonce confirmed) is no shortcut.
        $this->assertRefused('confirmation_invalid', fn () => $pay(new ResellerConfirmation((int) $e->quote?->wholesaleAmount, $confirmation->nonce)));
        [, , $now, $fresh] = $quote();
        self::assertSame(SettlementOutcome::CONFIRMED, $pay($fresh)->outcome);
        self::assertSame($balance - $now->wholesaleAmount, $this->balance());
    }

    public function testTheBotShowsAFreshConfirmationWhenThePriceChanged(): void
    {
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$this->plan->getId());
        $old = $this->sentCallback('rs_buy:');
        $this->reseller->setDiscountBp(1000);
        $this->em->flush();
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, $old);

        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertTrue($this->sentTextContains($this->botText('reseller.price_changed')));
        $fresh = $this->sentCallback('rs_buy:'.$this->plan->getId().':135000:');
        $this->pressButton(self::CUSTOMER, $fresh);
        self::assertSame(135000, (int) $this->em->getConnection()->fetchOne('SELECT amount FROM `order`'));
    }

    public function testAConfirmationExpiresAndAForgedOneIsRefused(): void
    {
        $past = $this->confirmationsAt(new \DateTimeImmutable('-'.(ResellerConfirmations::TTL_SECONDS + 5).' seconds'));
        $old = $past->issue($this->reseller, ResellerConfirmations::KIND_NEW, (int) $this->plan->getId(), 0, 0, 120000);
        $this->assertRefused('confirmation_expired', fn () => $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), $old));

        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->plan->getId());
        $otherPlan = $this->createPlan(150000, $this->inbound);
        foreach ([
            'another plan' => fn () => $this->checkout()->buyNewService($this->user, (int) $otherPlan->getId(), $confirmation),
            'a lower amount' => fn () => $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), new ResellerConfirmation(1000, $confirmation->nonce)),
            'a made-up nonce' => fn () => $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), new ResellerConfirmation(120000, '0000000000000aaaaaaaaaa')),
            'no nonce (an old button)' => fn () => $this->checkout()->buyNewService($this->user, (int) $this->plan->getId(), new ResellerConfirmation(120000, '')),
        ] as $case => $call) {
            $this->assertRefused('confirmation_invalid', $call, null, $case);
        }
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertSame(1000000, $this->balance());
    }

    public function testTheDatabaseKeepsANonceUniquePerReseller(): void
    {
        $this->buy();
        $connection = $this->em->getConnection();
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $connection->executeStatement('INSERT INTO `order` (user_id, plan_id, amount, type, status, created_at, reseller_id, reseller_nonce) SELECT user_id, plan_id, amount, type, status, created_at, reseller_id, reseller_nonce FROM `order`');
    }

    private function assertBoughtOnce(int $amount): void
    {
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'one order');
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'purchase'"), 'one debit');
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM vpn_service'));
        self::assertSame(1000000 - $amount, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function assertRefused(string $error, \Closure $call, ?int $orderId = null, string $case = ''): void
    {
        try {
            $call();
            self::fail('accepted: '.$case);
        } catch (ResellerException $e) {
            self::assertSame($error, $e->error, $case);
            if (null !== $orderId) {
                self::assertSame($orderId, $e->orderId);
            }
        }
    }

    /** The confirmations as they would be issued at another time (same secret as the application). */
    private function confirmationsAt(\DateTimeImmutable $at): ResellerConfirmations
    {
        return new ResellerConfirmations(new MockClock($at), (string) ($_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? ''));
    }

    private function sentCallback(string $prefix): string
    {
        $matching = array_values(array_filter($this->sentCallbacks(), static fn (string $data): bool => str_starts_with($data, $prefix)));
        self::assertNotSame([], $matching, 'no button '.$prefix.'…');

        return $matching[count($matching) - 1];
    }

    /**
     * @param list<list<string>> $commands
     *
     * @return list<string>
     */
    private function parallel(array $commands): array
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
