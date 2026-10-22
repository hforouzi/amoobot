<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\RequiredChannel;
use App\Entity\TrialPlan;
use App\Entity\VpnService;
use App\Shop\Application\SalesSettingsProvider;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Buying a new service in the Mini App: the plans for sale, a checkout priced only by the server, discount codes,
 * double-submit protection (Idempotency-Key), incomplete orders, trials, the sales switch, the channel gate, CSRF
 * and the rate limit. The order is the same one the bot creates (Shop\Application\Checkout).
 */
final class MiniAppPurchaseTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM trial_claim');
        $this->em->getConnection()->executeStatement('DELETE FROM trial_plan');
        parent::tearDown();
    }

    public function testTheShopListsOnlyPlansForSaleWithTheServerPrice(): void
    {
        $this->createPlan(99000)->setIsActive(false);
        $closedPanelPlan = $this->createPlan(88000, $this->createTestInbound());
        $closedPanelPlan->getInbound()?->getPanel()->setIsActive(false);
        $this->em->flush();

        $html = $this->appGet('/app/view/shop', $this->authenticate(self::CUSTOMER));
        $page = new Crawler($html);

        self::assertSame(200, $this->httpStatus());
        self::assertSame([(int) $this->plan->getId()], $page->filter('[data-plan-id]')->each(static fn (Crawler $c): int => (int) $c->attr('data-plan-id')));
        self::assertStringContainsString('150,000', $html);
        self::assertSame('/app/view/shop/plans/'.$this->plan->getId(), $page->filter('[data-plan-id] a[data-nav]')->attr('href'));
    }

    public function testNoPlansForSaleShowsAnEmptyShop(): void
    {
        $this->plan->setIsActive(false);
        $this->em->flush();

        $page = new Crawler($this->appGet('/app/view/shop', $this->authenticate(self::CUSTOMER)));

        self::assertCount(1, $page->filter('[data-no-plans]'));
    }

    public function testSalesClosedShowsTheMessageAndRefusesTheCheckout(): void
    {
        $this->setting(SalesSettingsProvider::NEW_ORDERS_ENABLED, 'false');
        $this->setting(SalesSettingsProvider::DISABLED_MESSAGE, 'فروش تا فردا بسته است.');
        $session = $this->authenticate(self::CUSTOMER);

        $html = $this->appGet('/app/view/shop', $session);
        self::assertStringContainsString('فروش تا فردا بسته است.', $html);
        self::assertCount(0, (new Crawler($html))->filter('[data-plan-id]'));

        $answer = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], self::newKey());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_closed', $answer['error']);
        self::assertSame(0, $this->rows('`order`'));
    }

    public function testTheCheckoutCreatesTheOrderAtTheServerPriceWhateverTheClientSends(): void
    {
        $session = $this->authenticate(self::CUSTOMER);

        $answer = $this->appPostJson('/app/api/checkout/new', $session, [
            'planId' => $this->plan->getId(), 'accountName' => 'Sara.Shop',
            'price' => 1, 'amount' => 1, 'finalAmount' => 1, 'discountAmount' => 149999, 'planTitle' => 'free', 'trafficGb' => 999, 'durationDays' => 999,
        ], self::newKey());

        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '');
        $order = $this->onlyOrder();
        self::assertSame('/app/view/orders/'.$order->getId().'/pay', $answer['redirect']);
        self::assertSame(OrderType::NEW_SERVICE, $order->getType());
        self::assertSame(OrderStatus::WAITING_PAYMENT, $order->getStatus());
        self::assertSame(150000, $order->getAmount(), 'the plan price, not the client\'s');
        $metadata = $order->getMetadata() ?? [];
        self::assertSame(50, $metadata['trafficGb'], 'a non-customizable plan keeps its own traffic');
        self::assertSame(30, $metadata['durationDays']);
        self::assertStringStartsWith('sarashop_', (string) $metadata['finalUsername']);
        self::assertSame(150000, $metadata['priceSnapshot']['finalAmount']);
        self::assertSame(0, $this->rows('payment'), 'the payment starts when a method is chosen');
    }

    public function testACustomizablePlanChecksTrafficAndDurationAndPricesThem(): void
    {
        $this->plan->setIsCustomizable(true)->setMinTrafficGb(10)->setMaxTrafficGb(100)->setPricePerGb(2000)->setMinDurationDays(30)->setMaxDurationDays(90)->setPricePerDay(1000);
        $this->em->flush();
        $session = $this->authenticate(self::CUSTOMER);

        $form = new Crawler($this->appGet('/app/view/shop/plans/'.$this->plan->getId(), $session));
        self::assertCount(1, $form->filter('input[name="trafficGb"][min="10"][max="100"]'));
        self::assertCount(1, $form->filter('input[name="durationDays"][min="30"][max="90"]'));

        $answer = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'nima', 'trafficGb' => 500, 'durationDays' => 60], self::newKey());
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_traffic', $answer['error']);
        $answer = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'nima', 'trafficGb' => 40, 'durationDays' => 5], self::newKey());
        self::assertSame('invalid_duration', $answer['error']);
        $answer = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => '!!', 'trafficGb' => 40, 'durationDays' => 60], self::newKey());
        self::assertSame('invalid_name', $answer['error']);
        self::assertSame(0, $this->rows('`order`'));

        $quote = $this->appPostJson('/app/api/checkout/quote', $session, ['planId' => $this->plan->getId(), 'trafficGb' => 40, 'durationDays' => 60]);
        self::assertSame(200, $this->httpStatus());
        $expected = self::getContainer()->get(\App\Shop\Application\PlanPricingService::class)->calculateNewOrderPrice($this->plan, ['trafficGb' => 40, 'durationDays' => 60])->afterGlobalDiscountAmount;
        self::assertSame($expected, $quote['finalAmount']);
        self::assertStringContainsString(number_format($expected), (string) $quote['html']);

        $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'nima', 'trafficGb' => 40, 'durationDays' => 60], self::newKey());
        self::assertSame(200, $this->httpStatus());
        $order = $this->onlyOrder();
        self::assertSame($expected, $order->getAmount());
        self::assertSame(40, $order->getMetadata()['trafficGb']);
        self::assertSame(60, $order->getMetadata()['durationDays']);
    }

    public function testAValidDiscountCodeIsAppliedByTheServer(): void
    {
        $this->discountCode('OFF20', DiscountCode::TYPE_PERCENT, 20);
        $session = $this->authenticate(self::CUSTOMER);

        $quote = $this->appPostJson('/app/api/checkout/quote', $session, ['planId' => $this->plan->getId(), 'discountCode' => 'off20']);
        self::assertTrue($quote['discountApplied']);
        self::assertSame(120000, $quote['finalAmount']);

        $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara', 'discountCode' => 'off20', 'finalAmount' => 1], self::newKey());

        $order = $this->onlyOrder();
        self::assertSame(120000, $order->getAmount());
        self::assertSame('OFF20', $order->getMetadata()['priceSnapshot']['discountCode']);
        self::assertSame(30000, $order->getMetadata()['priceSnapshot']['discountCodeAmount']);
    }

    /**
     * @return iterable<string, array{\Closure(DiscountCode): mixed}>
     */
    public static function unusableCodes(): iterable
    {
        yield 'expired' => [static fn (DiscountCode $d): mixed => $d->setEndsAt(new \DateTimeImmutable('-1 day'))];
        yield 'not started' => [static fn (DiscountCode $d): mixed => $d->setStartsAt(new \DateTimeImmutable('+1 day'))];
        yield 'used up' => [static fn (DiscountCode $d): mixed => $d->setMaxUses(3)->setUsedCount(3)];
        yield 'inactive' => [static fn (DiscountCode $d): mixed => $d->setIsActive(false)];
    }

    /**
     * @param \Closure(DiscountCode): mixed $configure
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableCodes')]
    public function testAnUnusableDiscountCodeIsRefusedBeforeAnyOrderExists(\Closure $configure): void
    {
        $this->discountCode('DEAD', DiscountCode::TYPE_FIXED, 50000, $configure);
        $session = $this->authenticate(self::CUSTOMER);

        $quote = $this->appPostJson('/app/api/checkout/quote', $session, ['planId' => $this->plan->getId(), 'discountCode' => 'dead']);
        self::assertFalse($quote['discountApplied']);
        self::assertSame(150000, $quote['finalAmount']);
        self::assertNotSame('', (string) $quote['discountMessage']);

        $answer = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara', 'discountCode' => 'dead'], self::newKey());
        self::assertSame(422, $this->httpStatus());
        self::assertSame('discount_invalid', $answer['error']);
        self::assertSame(0, $this->rows('`order`'));
    }

    public function testDoubleSubmitWithTheSameKeyCreatesOneOrderAndOnePayment(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $key = self::newKey();

        $first = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], $key);
        $second = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], $key);

        self::assertSame(200, $this->httpStatus());
        self::assertSame($first['redirect'], $second['redirect']);
        self::assertSame(1, $this->rows('`order`'));

        $order = $this->onlyOrder();
        $payKey = self::newKey();
        $pay1 = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $this->methods['zibal']->getId()], $payKey);
        $pay2 = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $this->methods['zibal']->getId()], $payKey);
        self::assertSame($pay1['redirect'], $pay2['redirect']);
        self::assertSame(1, $this->rows('payment'));
        self::assertCount(1, $this->http->requests('gateway.zibal.ir/v1/request'), 'the gateway is asked once');

        $other = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], self::newKey());
        self::assertNotSame($first['redirect'], $other['redirect'], 'a new key is a new order');
        self::assertSame(2, $this->rows('`order`'));
    }

    public function testAKeyIsRequiredAndCannotBeReusedForSomethingElse(): void
    {
        $session = $this->authenticate(self::CUSTOMER);

        $answer = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara']);
        self::assertSame(400, $this->httpStatus());
        self::assertSame('idempotency_key_required', $answer['error']);

        $key = self::newKey();
        $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], $key);
        $order = $this->onlyOrder();
        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $this->methods['manual_card']->getId()], $key);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('idempotency_conflict', $answer['error']);
    }

    public function testAPlanThatIsNotForSaleCannotBeBought(): void
    {
        $hidden = $this->createPlan(1000, $this->inbound)->setIsActive(false);
        $this->em->flush();
        $session = $this->authenticate(self::CUSTOMER);

        foreach ([(int) $hidden->getId(), 999999] as $planId) {
            $answer = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $planId, 'accountName' => 'sara'], self::newKey());
            self::assertSame(404, $this->httpStatus());
            self::assertSame('plan_unavailable', $answer['error']);
            $this->appGet('/app/view/shop/plans/'.$planId, $session);
            self::assertSame(404, $this->httpStatus());
        }
        self::assertSame(0, $this->rows('`order`'));
    }

    public function testTheIncompleteOrderCanBeResumedOrCancelled(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], self::newKey());
        $order = $this->onlyOrder();

        $card = (new Crawler($this->appGet('/app/view/shop', $session)))->filter('[data-incomplete-order]');
        self::assertSame((string) $order->getId(), $card->attr('data-incomplete-order'));
        self::assertSame('/app/view/orders/'.$order->getId().'/pay', $card->filter('a[data-nav]')->attr('href'));
        self::assertSame('/app/api/orders/'.$order->getId().'/cancel', $card->filter('button[data-action]')->attr('data-action'));

        $this->appPostJson('/app/api/orders/'.$order->getId().'/cancel', $session, [], self::newKey());
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();
        self::assertSame(OrderStatus::CANCELLED, $this->em->find(Order::class, $order->getId())?->getStatus());
        self::assertCount(0, (new Crawler($this->appGet('/app/view/shop', $session)))->filter('[data-incomplete-order]'));
    }

    public function testSomeoneElsesOrderCannotBePaidOrCancelled(): void
    {
        $theirs = $this->createOrder($this->createUser('8602'), $this->plan);
        $session = $this->authenticate(self::CUSTOMER);

        $this->appGet('/app/view/orders/'.$theirs->getId().'/pay', $session);
        self::assertSame(404, $this->httpStatus());
        $this->appPostJson('/app/api/orders/'.$theirs->getId().'/pay', $session, ['methodId' => $this->methods['manual_card']->getId()], self::newKey());
        self::assertSame(404, $this->httpStatus());
        $this->appPostJson('/app/api/orders/'.$theirs->getId().'/cancel', $session, [], self::newKey());
        self::assertSame(404, $this->httpStatus());
        self::assertSame(0, $this->rows('payment'));
    }

    public function testATrialIsClaimedOnceWhenEligible(): void
    {
        $trial = (new TrialPlan())->setTitle('تست یک روزه')->setIsActive(true)->setDurationDays(1)->setTrafficGb(1)->setInbound($this->inbound)->setMaxClaimsPerUser(1);
        $this->em->persist($trial);
        $this->em->flush();
        $session = $this->authenticate(self::CUSTOMER);

        $button = (new Crawler($this->appGet('/app/view/shop', $session)))->filter('[data-trial-plan] button[data-action]');
        self::assertSame('/app/api/trial/'.$trial->getId(), $button->attr('data-action'));

        $answer = $this->appPostJson('/app/api/trial/'.$trial->getId(), $session, [], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '');
        $service = $this->em->getRepository(VpnService::class)->findOneBy(['user' => $this->user]);
        self::assertInstanceOf(VpnService::class, $service);
        self::assertSame('/app/view/services/'.$service->getId().'/subscription', $answer['redirect']);

        $answer = $this->appPostJson('/app/api/trial/'.$trial->getId(), $session, [], self::newKey());
        self::assertSame(422, $this->httpStatus());
        self::assertSame('trial_refused', $answer['error']);
        self::assertNotSame('', (string) $answer['message'], 'TrialAccountService says why');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service WHERE user_id = ?', [$this->user->getId()]));
    }

    public function testRequiredChannelsMustBeJoinedFirst(): void
    {
        $this->em->persist((new RequiredChannel())->setTitle('کانال ما')->setChatId('@shop_channel')->setInviteUrl('https://t.me/shop_channel')->setIsActive(true)->setRequireForPurchase(true));
        $this->em->flush();
        $this->http->onJson('/getChatMember', ['ok' => true, 'result' => ['status' => 'left']]);
        $session = $this->authenticate(self::CUSTOMER);

        $answer = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], self::newKey());

        self::assertSame(403, $this->httpStatus());
        self::assertSame('membership_required', $answer['error']);
        self::assertSame([['title' => 'کانال ما', 'url' => 'https://t.me/shop_channel']], $answer['channels']);
        self::assertSame(0, $this->rows('`order`'));
    }

    public function testCheckoutNeedsCsrfAndIsRateLimited(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], self::newKey(), 'forged');
        self::assertSame(403, $this->httpStatus());
        self::assertSame(0, $this->rows('`order`'));

        for ($i = 0; $i < 20; ++$i) {
            $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], self::newKey());
            self::assertSame(200, $this->httpStatus(), 'attempt '.$i);
        }
        $answer = $this->appPostJson('/app/api/checkout/new', $session, ['planId' => $this->plan->getId(), 'accountName' => 'sara'], self::newKey());
        self::assertSame(429, $this->httpStatus());
        self::assertSame('rate_limited', $answer['error']);
        self::assertSame(20, $this->rows('`order`'));
    }

    private function onlyOrder(): Order
    {
        $this->em->clear();
        $orders = $this->em->getRepository(Order::class)->findBy(['user' => $this->user->getId()], ['id' => 'DESC']);
        self::assertNotEmpty($orders);

        return $orders[0];
    }
}
