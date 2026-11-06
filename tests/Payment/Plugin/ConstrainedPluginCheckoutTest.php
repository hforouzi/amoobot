<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Payment;
use App\Entity\Plan;
use App\MiniApp\Guide\GuideStateProvider;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ConstrainedGatewayFixture;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

/**
 * What a customer is offered with a constrained plugin installed, through the real checkouts: the bot's method buttons
 * and the Mini App's method list both come from StorePaymentMethodResolver. The other gateways' lists do not change.
 */
final class ConstrainedPluginCheckoutTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ConstrainedGatewayFixture;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    private const TITLE = 'درگاه محدود';
    private const CORE_TITLES = ['کارت به کارت', 'زیبال', 'درگاه اختصاصی', 'ارز دیجیتال'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->installConstrainedPlugin();
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownConstrainedGateway();
        parent::tearDown();
    }

    private function planFor(int $price): Plan
    {
        $this->plan->setPrice($price);
        $this->em->flush();

        return $this->plan;
    }

    /**
     * Up to the bot's list of payment methods.
     *
     * @return list<string> the titles on the method buttons
     */
    private function botMethodTitles(int $price): array
    {
        $plan = $this->planFor($price);
        $this->pressButton(self::CUSTOMER, 'select_plan:'.$plan->getId());
        $this->sendText(self::CUSTOMER, 'nima');
        $draft = $this->em->getRepository(OrderDraft::class)->findOneBy([], ['id' => 'DESC']) ?? throw new \LogicException();
        $this->pressButton(self::CUSTOMER, 'custom_order_confirm:'.$draft->getId());
        $order = $this->em->getRepository(Order::class)->findOneBy([], ['id' => 'DESC']) ?? throw new \LogicException();
        $this->http->reset();
        $this->pressButton(self::CUSTOMER, 'discount_skip_order:'.$order->getId());

        $titles = [];
        foreach ($this->sentKeyboards() as $keyboard) {
            foreach ($keyboard as $row) {
                foreach ($row as $button) {
                    if (str_starts_with((string) ($button['callback_data'] ?? ''), 'select_store_payment_method:')) {
                        $titles[] = (string) $button['text'];
                    }
                }
            }
        }

        return $titles;
    }

    /**
     * @return list<string> the method titles of the Mini App's pay page
     */
    private function miniAppMethodTitles(int $price): array
    {
        return $this->payPageTitles((int) $this->createOrder($this->user, $this->planFor($price))->getId());
    }

    /**
     * @return list<string>
     */
    private function payPageTitles(int $orderId): array
    {
        $html = $this->appGet('/app/view/orders/'.$orderId.'/pay', $this->authenticate(self::CUSTOMER));
        self::assertSame(200, $this->httpStatus());

        return (new Crawler($html))->filter('[data-method-id] .list-main')->each(static fn (Crawler $node): string => trim($node->text()));
    }

    /**
     * @param list<string> $titles
     */
    private static function buttonOffered(string $title, array $titles): bool
    {
        foreach ($titles as $text) {
            if (str_contains($text, $title)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function prices(): iterable
    {
        yield 'below the range' => [49999, false];
        yield 'the minimum' => [50000, true];
        yield 'the maximum' => [10000000, true];
        yield 'above the range' => [10000001, false];
    }

    #[DataProvider('prices')]
    public function testTheBotShowsTheMethodOnlyInsideTheRange(int $price, bool $shown): void
    {
        $this->constrainedMethod();

        $titles = $this->botMethodTitles($price);

        self::assertSame($shown, self::buttonOffered(self::TITLE, $titles));
        foreach (self::CORE_TITLES as $title) {
            self::assertTrue(self::buttonOffered($title, $titles), $title.' stays offered');
        }
    }

    #[DataProvider('prices')]
    public function testTheMiniAppShowsTheMethodOnlyInsideTheRange(int $price, bool $shown): void
    {
        $this->constrainedMethod();

        $titles = $this->miniAppMethodTitles($price);

        self::assertSame($shown, in_array(self::TITLE, $titles, true));
        self::assertSame(self::CORE_TITLES, array_values(array_diff($titles, [self::TITLE])));
    }

    public function testASwitchedOffGatewayIsHiddenInBothCheckoutsAndTheGuide(): void
    {
        $this->constrainedMethod(['usable' => false]);

        self::assertFalse(self::buttonOffered(self::TITLE, $this->botMethodTitles(150000)));
        self::assertNotContains(self::TITLE, $this->miniAppMethodTitles(150000));
        self::assertNotContains(self::TITLE, self::getContainer()->get(GuideStateProvider::class)->current()->paymentMethods);
    }

    public function testAUsableGatewayIsInTheGuide(): void
    {
        $this->constrainedMethod();

        self::assertContains(self::TITLE, self::getContainer()->get(GuideStateProvider::class)->current()->paymentMethods);
    }

    public function testTheCoreListsAreTheSameWithAndWithoutThePlugin(): void
    {
        $orderId = (int) $this->createOrder($this->user, $this->planFor(150000))->getId();
        $without = $this->payPageTitles($orderId);
        $this->em->clear();
        $this->constrainedMethod(['usable' => false]);

        self::assertSame(self::CORE_TITLES, $without);
        self::assertSame($without, $this->payPageTitles($orderId));
    }

    public function testAHiddenMethodCannotBeStartedFromAStaleList(): void
    {
        $method = $this->constrainedMethod();
        $order = $this->createOrder($this->user, $this->planFor(49999));
        $session = $this->authenticate(self::CUSTOMER);

        $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $method->getId()], self::newKey());

        self::assertGreaterThanOrEqual(400, $this->httpStatus());
        self::assertNull($this->em->getRepository(Payment::class)->findOneBy(['order' => $order]));
    }
}
