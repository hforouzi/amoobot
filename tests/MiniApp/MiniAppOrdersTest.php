<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\Order;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Domain\OrderStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\MissingTranslationRecorder;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "My orders" in the Mini App: the customer's own orders, newest first, ten per page, each with its type, status,
 * amount, date and tracking code, and a link to its payment or its service.
 */
final class MiniAppOrdersTest extends IntegrationTestCase
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

    public function testOrdersArePagedNewestFirstAndOnlyTheCustomersOwn(): void
    {
        $ids = [];
        for ($i = 0; $i < 12; ++$i) {
            $ids[] = (int) $this->tracked($this->createOrder($this->user, $this->plan))->getId();
        }
        $theirs = $this->createOrder($this->createUser('8605'), $this->plan);
        $session = $this->authenticate(self::CUSTOMER);

        $page = new Crawler($this->appGet('/app/view/orders', $session));
        self::assertSame(200, $this->httpStatus());
        $shown = $page->filter('[data-order-id]')->each(static fn (Crawler $c): int => (int) $c->attr('data-order-id'));
        self::assertSame(array_slice(array_reverse($ids), 0, 10), $shown);
        self::assertNotContains((int) $theirs->getId(), $shown);
        self::assertSame('/app/view/orders?page=2', $page->filter('a[data-nav][data-next-page]')->attr('href'));
        self::assertCount(0, $page->filter('[data-previous-page]'));

        $page = new Crawler($this->appGet('/app/view/orders?page=2', $session));
        self::assertSame(array_slice(array_reverse($ids), 10), $page->filter('[data-order-id]')->each(static fn (Crawler $c): int => (int) $c->attr('data-order-id')));
        self::assertCount(0, $page->filter('[data-next-page]'));
        self::assertSame('/app/view/orders?page=1', $page->filter('a[data-nav][data-previous-page]')->attr('href'));

        $this->appGet('/app/view/orders?page=99', $session);
        self::assertSame(200, $this->httpStatus(), 'past the end: an empty page, not an error');
    }

    public function testEachOrderShowsTypeStatusAmountDateAndTrackingCode(): void
    {
        $order = $this->tracked($this->createOrder($this->user, $this->plan, amount: 123000));
        $session = $this->authenticate(self::CUSTOMER);

        $item = (new Crawler($this->appGet('/app/view/orders', $session)))->filter('[data-order-id="'.$order->getId().'"]');

        self::assertStringContainsString((string) $order->getTrackingCode(), $item->text());
        self::assertStringContainsString('123,000', $item->text());
        self::assertSame(OrderStatus::WAITING_PAYMENT, $item->attr('data-status'));
        self::assertCount(1, $item->filter('time[datetime]'));
        self::assertSame('/app/view/orders/'.$order->getId(), $item->filter('a[data-nav]')->attr('href'));
    }

    public function testTheOrderPageLinksToItsPaymentOrItsService(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $waiting = $this->tracked($this->createOrder($this->user, $this->plan));
        $paid = $this->tracked($this->createOrder($this->user, $this->plan));
        $payment = $this->createPayment($paid, $this->methods['zibal']->getGateway(), PaymentStatus::CONFIRMED);
        $service = $this->createActiveService($this->user, $this->inbound)->setOrder($paid);
        $this->em->flush();
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ? WHERE id = ?', [OrderStatus::PROVISIONED, $paid->getId()]);

        $page = new Crawler($this->appGet('/app/view/orders/'.$waiting->getId(), $session));
        self::assertSame('/app/view/orders/'.$waiting->getId().'/pay', $page->filter('a[data-nav][data-pay-order]')->attr('href'));

        $page = new Crawler($this->appGet('/app/view/orders/'.$paid->getId(), $session));
        self::assertSame('/app/view/payments/'.$payment->getId(), $page->filter('a[data-nav][data-payment-link]')->attr('href'));
        self::assertSame('/app/view/services/'.$service->getId(), $page->filter('a[data-nav][data-service-link]')->attr('href'));
        self::assertCount(0, $page->filter('[data-pay-order]'));
    }

    public function testSomeoneElsesOrderIsInvisible(): void
    {
        $theirs = $this->createOrder($this->createUser('8606'), $this->plan);
        $session = $this->authenticate(self::CUSTOMER);

        $this->appGet('/app/view/orders/'.$theirs->getId(), $session);

        self::assertSame(404, $this->httpStatus());
    }

    public function testTheOrdersPagesAreTranslated(): void
    {
        $recorder = self::getContainer()->get(MissingTranslationRecorder::class);
        $recorder->reset();
        $order = $this->tracked($this->createOrder($this->user, $this->plan));
        $session = $this->authenticate(self::CUSTOMER);

        foreach (['', '?lang=en'] as $query) {
            $this->appGet('/app/view/orders'.$query, $session);
            $this->appGet('/app/view/orders/'.$order->getId().$query, $session);
            $this->appGet('/app/view/services'.$query, $session);
            $this->appGet('/app/view/shop'.$query, $session);
            $this->appGet('/app/view/shop/plans/'.$this->plan->getId().$query, $session);
            $this->appGet('/app/view/orders/'.$order->getId().'/pay'.$query, $session);
        }

        self::assertSame([], $recorder->missing());
    }

    private function tracked(Order $order): Order
    {
        $order->setTrackingCode('TRK'.$order->getId());
        $this->em->flush();

        return $order;
    }
}
