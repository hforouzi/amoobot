<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Entity\Order;
use App\Entity\Plan;
use App\Reseller\UI\ResellerBotHandler;
use App\Shop\Application\SalesSettingsProvider;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Customizable plans in the reseller bot: after the plan, the traffic and the days (buttons or a typed number, exact,
 * within the plan's range), then the server's quote with a signed confirmation (plan, GB, days, amount); one tap buys
 * once. "New service" says why when it has nothing to show. Fixed plans are unchanged.
 */
final class ResellerBotCustomizableTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ResellerFixtures;
    use SubprocessAssertions;

    private Plan $custom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller();
        $this->fund(1000000);
        // 10-200 GB at 2,000 Toman, 30-90 days at 1,000 Toman; the reseller gets 20 % off.
        $this->custom = (new Plan())->setTitle('Custom')->setDurationDays(30)->setTrafficGb(10)->setPrice(0)->setInbound($this->inbound)
            ->setIsCustomizable(true)->setMinTrafficGb(10)->setMaxTrafficGb(200)->setPricePerGb(2000)
            ->setMinDurationDays(30)->setMaxDurationDays(90)->setPricePerDay(1000);
        $this->em->persist($this->custom);
        $this->em->flush();
        self::getContainer()->get(CacheItemPoolInterface::class)->deleteItem('reseller_bot_prompt_'.self::CUSTOMER);
        $this->http->reset();
        $this->answerGateways();
    }

    public function testTheCustomizablePlanIsOfferedWithItsFromPrice(): void
    {
        $this->pressButton(self::CUSTOMER, 'rs_new');

        self::assertContains('rs_plan:'.$this->custom->getId(), $this->sentCallbacks());
        self::assertTrue($this->buttonTextContains($this->botText('reseller.plan_button_from', ['plan' => ['title' => 'Custom'], 'reseller' => ['wholesale' => '40,000']])), '(10 x 2,000 + 30 x 1,000) - 20 %');
        self::assertContains('rs_plan:'.$this->plan->getId(), $this->sentCallbacks(), 'fixed plans still offered');
    }

    public function testBuyingWithButtons(): void
    {
        $id = (int) $this->custom->getId();
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$id);
        self::assertTrue($this->sentTextContains($this->botText('reseller.ask_traffic', ['range' => ['min' => 10, 'max' => 200]])));
        self::assertSame(["rs_cg:$id:10", "rs_cg:$id:20", "rs_cg:$id:30", "rs_cg:$id:50", "rs_cg:$id:100", "rs_cg:$id:200"], array_values(array_filter($this->sentCallbacks(), static fn (string $d): bool => str_starts_with($d, 'rs_cg:'))));

        $this->pressButton(self::CUSTOMER, "rs_cg:$id:50");
        self::assertContains("rs_cd:$id:50:60", $this->sentCallbacks());
        $this->pressButton(self::CUSTOMER, "rs_cd:$id:50:60");
        $pay = $this->lastCallback("rs_buyc:$id:50:60:128000:");
        self::assertMatchesRegularExpression('/^rs_buyc:\d+:50:60:128000:[0-9a-z]{7}[0-9a-f]{6}[A-Za-z0-9_-]{10}$/D', $pay);
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'the confirmation writes nothing');

        $this->pressButton(self::CUSTOMER, $pay);

        $order = $this->onlyOrder();
        self::assertSame(128000, $order->getAmount(), '50 x 2,000 + 60 x 1,000 = 160,000 - 20 %');
        self::assertSame(50, $order->getMetadata()['trafficGb'] ?? null);
        self::assertSame(60, $order->getMetadata()['durationDays'] ?? null);
        self::assertSame(1000000 - 128000, $this->balance());
    }

    public function testBuyingWithTypedNumbers(): void
    {
        $id = (int) $this->custom->getId();
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$id);
        $this->sendText(self::CUSTOMER, '۵۰');
        self::assertTrue($this->sentTextContains($this->botText('reseller.ask_days', ['range' => ['min' => 30, 'max' => 90]])), 'Persian digits are digits');
        $this->sendText(self::CUSTOMER, '45');
        $this->pressButton(self::CUSTOMER, $this->lastCallback("rs_buyc:$id:50:45:"));

        self::assertSame(116000, $this->onlyOrder()->getAmount(), '(100,000 + 45,000) - 20 %');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typedAnswers(): iterable
    {
        yield 'below the minimum' => ['5', 'reseller.prompt_out_of_range'];
        yield 'above the maximum' => ['500', 'reseller.prompt_out_of_range'];
        yield 'zero' => ['0', 'reseller.prompt_out_of_range'];
        yield 'not a number' => ['fifty', 'reseller.prompt_not_a_number'];
        yield 'a decimal' => ['50.5', 'reseller.prompt_not_a_number'];
        yield 'negative' => ['-50', 'reseller.prompt_not_a_number'];
        yield 'trailing space' => ['50 ', 'reseller.prompt_not_a_number'];
        yield 'trailing newline' => ["50\n", 'reseller.prompt_not_a_number'];
        yield 'leading space' => [' 50', 'reseller.prompt_not_a_number'];
        yield 'a unit' => ['50GB', 'reseller.prompt_not_a_number'];
        yield 'seven digits' => ['1000000', 'reseller.prompt_not_a_number'];
    }

    #[DataProvider('typedAnswers')]
    public function testATypedAnswerIsTakenExactlyAndAskedAgainWhenWrong(string $answer, string $message): void
    {
        $id = (int) $this->custom->getId();
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$id);
        $this->http->reset();

        $this->sendText(self::CUSTOMER, $answer);

        self::assertTrue($this->sentTextContains($this->botText($message, ['range' => ['min' => 10, 'max' => 200]])), $answer);
        self::assertFalse($this->sentTextContains($this->botText('reseller.ask_days', ['range' => ['min' => 30, 'max' => 90]])));
        // The question stays: a right answer goes on.
        $this->sendText(self::CUSTOMER, '50');
        self::assertTrue($this->sentTextContains($this->botText('reseller.ask_days', ['range' => ['min' => 30, 'max' => 90]])));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forgedCallbacks(): iterable
    {
        yield 'GB below the minimum' => ['rs_cg:%d:5'];
        yield 'GB above the maximum' => ['rs_cg:%d:500'];
        yield 'days above the maximum' => ['rs_cd:%d:50:500'];
        yield 'GB with a space' => ['rs_cg:%d:50 '];
        yield 'GB with a newline' => ["rs_cg:%d:50\n"];
        yield 'an extra part' => ['rs_cg:%d:50:1'];
        yield 'a missing part' => ['rs_cd:%d:50'];
    }

    #[DataProvider('forgedCallbacks')]
    public function testAForgedChoiceIsRefused(string $data): void
    {
        $this->pressButton(self::CUSTOMER, sprintf($data, $this->custom->getId()));

        self::assertNotContains(true, array_map(static fn (string $d): bool => str_starts_with($d, 'rs_buyc:'), $this->sentCallbacks()), 'no pay button');
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
    }

    /** The pay button's GB / days / amount are covered by the nonce: changing one is a new confirmation, never a purchase. */
    public function testAnAlteredPayButtonBuysNothing(): void
    {
        $id = (int) $this->custom->getId();
        $this->pressButton(self::CUSTOMER, "rs_cd:$id:50:60");
        $pay = $this->lastCallback("rs_buyc:$id:50:60:128000:");
        $nonce = substr($pay, strrpos($pay, ':') + 1);
        $this->http->reset();

        foreach (["rs_buyc:$id:100:60:128000:$nonce", "rs_buyc:$id:50:90:128000:$nonce", "rs_buyc:$id:50:60:1000:$nonce", $pay."\n", $pay.' '] as $altered) {
            $this->pressButton(self::CUSTOMER, $altered);
        }

        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertTrue($this->sentTextContains($this->botText('reseller.confirmation_expired')), 'a fresh confirmation instead');
    }

    public function testTheSamePayButtonTwiceBuysOnce(): void
    {
        $id = (int) $this->custom->getId();
        $this->pressButton(self::CUSTOMER, "rs_cd:$id:50:60");
        $pay = $this->lastCallback("rs_buyc:$id:50:60:");
        $this->pressButton(self::CUSTOMER, $pay);
        $this->pressButton(self::CUSTOMER, $pay);

        $this->assertBoughtOnce(128000);
    }

    #[Group('subprocess')]
    public function testTheSameConfirmationDeliveredTwiceAtOnceBuysOnce(): void
    {
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->custom->getId(), 50, 60);
        $arguments = ['reseller-buy.php', (string) $this->user->getId(), (string) $this->custom->getId(), (string) $confirmation->amount, $confirmation->nonce, '50', '60'];

        $outcomes = $this->parallel([$arguments, $arguments]);
        sort($outcomes);

        self::assertSame(['already_processed', 'confirmed'], $outcomes);
        $this->assertBoughtOnce(128000);
    }

    public function testAChangedPriceShowsAFreshConfirmation(): void
    {
        $id = (int) $this->custom->getId();
        $this->pressButton(self::CUSTOMER, "rs_cd:$id:50:60");
        $old = $this->lastCallback("rs_buyc:$id:50:60:128000:");
        $this->reseller->setDiscountBp(1000);
        $this->em->flush();
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, $old);

        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
        self::assertTrue($this->sentTextContains($this->botText('reseller.price_changed')));
        $this->pressButton(self::CUSTOMER, $this->lastCallback("rs_buyc:$id:50:60:144000:"));
        self::assertSame(144000, $this->onlyOrder()->getAmount());
    }

    public function testAnExpiredQuestionSaysSo(): void
    {
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$this->custom->getId());
        $pool = self::getContainer()->get(CacheItemPoolInterface::class);
        $item = $pool->getItem('reseller_bot_prompt_'.self::CUSTOMER);
        $item->set(['kind' => ResellerBotHandler::PROMPT_TRAFFIC, 'target' => (string) $this->custom->getId(), 'until' => time() - 1]);
        $pool->save($item);
        $this->http->reset();

        $this->sendText(self::CUSTOMER, '50');

        self::assertTrue($this->sentTextContains($this->botText('reseller.prompt_expired')));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM `order`'));
    }

    public function testRenewalAndExtraTrafficOfACustomizableService(): void
    {
        $this->setting('traffic_addon.enabled', 'true');
        $this->setting('traffic_addon.min_gb', '1');
        $this->setting('traffic_addon.max_gb', '100');
        $this->setting('traffic_addon.price_per_gb', '3000');
        [, , , $confirmation] = $this->checkout()->quoteNewService($this->user, (int) $this->custom->getId(), 50, 60);
        $this->checkout()->buyNewService($this->user, (int) $this->custom->getId(), $confirmation, 50, 60);
        $serviceId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM vpn_service');

        [, , $renewal] = $this->checkout()->quoteRenewal($this->user, $serviceId);
        self::assertSame(128000, $renewal->wholesaleAmount, "the source order's 50 GB / 60 days at the plan's prices now, minus 20 %");
        $this->renewSold($serviceId);
        $this->addTrafficSold($serviceId, 10);
        self::assertSame(['new_service', 'renewal', 'add_traffic'], array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT type FROM `order` ORDER BY id')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function emptyStates(): iterable
    {
        yield 'sales closed' => ['sales_closed', 'reseller.error.sales_closed'];
        yield 'nothing configured' => ['no_plans', 'reseller.plans_empty'];
        yield 'nothing for sale' => ['not_for_sale', 'reseller.plans_not_for_sale'];
        yield 'nothing priceable for resellers' => ['no_wholesale', 'reseller.plans_no_wholesale'];
    }

    #[DataProvider('emptyStates')]
    public function testNewServiceSaysWhyItHasNothingToShow(string $state, string $message): void
    {
        $connection = $this->em->getConnection();
        match ($state) {
            'sales_closed' => $this->setting(SalesSettingsProvider::NEW_ORDERS_ENABLED, 'false'),
            'no_plans' => $connection->executeStatement('UPDATE plan SET is_active = 0'),
            'not_for_sale' => $connection->executeStatement('UPDATE vpn_inbound SET is_active = 0'),
            default => $connection->executeStatement('UPDATE plan SET price = 0, price_per_gb = 0, price_per_day = 0'),
        };
        $this->em->clear();

        $this->pressButton(self::CUSTOMER, 'rs_new');

        self::assertTrue($this->sentTextContains($this->botText($message)), $state);
        foreach (['reseller.error.sales_closed', 'reseller.plans_empty', 'reseller.plans_not_for_sale', 'reseller.plans_no_wholesale'] as $other) {
            if ($other !== $message) {
                self::assertFalse($this->sentTextContains($this->botText($other)), $state.' is not '.$other);
            }
        }
    }

    public function testAFixedPlanStillGoesStraightToItsConfirmation(): void
    {
        $this->pressButton(self::CUSTOMER, 'rs_plan:'.$this->plan->getId());

        $pay = 'rs_buy:'.$this->plan->getId().':120000:';
        self::assertNotSame([], array_filter($this->sentCallbacks(), static fn (string $d): bool => str_starts_with($d, $pay)));
        self::assertSame([], array_filter($this->sentCallbacks(), static fn (string $d): bool => str_starts_with($d, 'rs_cg:')));
    }

    private function assertBoughtOnce(int $amount): void
    {
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM `order`'), 'one order');
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM wallet_transaction WHERE type = 'purchase'"), 'one debit');
        self::assertSame(1000000 - $amount, $this->balance());
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    private function onlyOrder(): Order
    {
        $orders = $this->em->getRepository(Order::class)->findAll();
        self::assertCount(1, $orders);
        $this->em->refresh($orders[0]);

        return $orders[0];
    }

    private function lastCallback(string $prefix): string
    {
        $matching = array_values(array_filter($this->sentCallbacks(), static fn (string $data): bool => str_starts_with($data, $prefix)));
        self::assertNotSame([], $matching, 'no button '.$prefix.'…');

        return $matching[count($matching) - 1];
    }

    private function buttonTextContains(string $needle): bool
    {
        foreach ($this->sentKeyboards() as $keyboard) {
            foreach ($keyboard as $row) {
                foreach ($row as $button) {
                    if (str_contains((string) ($button['text'] ?? ''), $needle)) {
                        return true;
                    }
                }
            }
        }

        return false;
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
