<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\VerifiedPayment;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Shop\Application\SalesSettingsProvider;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Renewing a service and buying extra traffic from its page in the Mini App: the same quotes, switches and order
 * types as the bot (ServiceOrderCheckout), then the same payment flow. Every service id is checked for ownership.
 */
final class MiniAppRenewAddTrafficTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    private VpnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->setting('traffic_addon.price_per_gb', '3000');
        $this->service = $this->createActiveService($this->user, $this->inbound);
        $this->service->setOrder($this->createOrder($this->user, $this->plan));
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ? WHERE id = ?', [OrderStatus::PROVISIONED, $this->service->getOrder()?->getId()]);
        $this->em->flush();
        $this->startMiniAppClient();
    }

    public function testTheServicePageOffersRenewAndAddTrafficWhenSalesAllowIt(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $page = new Crawler($this->appGet('/app/view/services/'.$this->service->getId(), $session));

        self::assertSame('/app/view/services/'.$this->service->getId().'/renew', $page->filter('a[data-nav][data-renew]')->attr('href'));
        self::assertSame('/app/view/services/'.$this->service->getId().'/add-traffic', $page->filter('a[data-nav][data-add-traffic]')->attr('href'));

        $this->setting(SalesSettingsProvider::RENEWALS_ENABLED, 'false');
        $this->setting(SalesSettingsProvider::ADD_TRAFFIC_ENABLED, 'false');
        $page = new Crawler($this->appGet('/app/view/services/'.$this->service->getId(), $session));
        self::assertCount(0, $page->filter('[data-renew], [data-add-traffic]'));
    }

    public function testRenewingCreatesTheBotsRenewalOrderAtTheQuotedPrice(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $expected = self::getContainer()->get(\App\Shop\Application\Checkout\ServiceOrderCheckout::class)->renewalQuote($this->service)?->amount;
        self::assertNotNull($expected);

        $html = $this->appGet('/app/view/services/'.$this->service->getId().'/renew', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString(number_format($expected), $html);

        $answer = $this->appPostJson('/app/api/services/'.$this->service->getId().'/renew', $session, ['amount' => 1], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '');

        $order = $this->latestOrder();
        self::assertSame(OrderType::RENEWAL, $order->getType());
        self::assertSame($this->service->getId(), $order->getTargetService()?->getId());
        self::assertSame($expected, $order->getAmount());
        self::assertSame('/app/view/orders/'.$order->getId().'/pay', $answer['redirect']);
    }

    public function testExtraTrafficIsPricedPerGbAndChecked(): void
    {
        $this->discountCode('GB5', DiscountCode::TYPE_FIXED, 5000);
        $session = $this->authenticate(self::CUSTOMER);
        $url = '/app/api/services/'.$this->service->getId().'/add-traffic';

        $form = new Crawler($this->appGet('/app/view/services/'.$this->service->getId().'/add-traffic', $session));
        self::assertCount(1, $form->filter('input[name="trafficGb"][min="1"][max="100"]'));

        $quote = $this->appPostJson($url.'/quote', $session, ['trafficGb' => 10, 'discountCode' => 'gb5']);
        self::assertSame(25000, $quote['finalAmount']);

        $answer = $this->appPostJson($url, $session, ['trafficGb' => 500], self::newKey());
        self::assertSame(422, $this->httpStatus());
        self::assertSame('invalid_traffic', $answer['error']);

        $this->appPostJson($url, $session, ['trafficGb' => 10, 'discountCode' => 'gb5', 'finalAmount' => 1], self::newKey());
        self::assertSame(200, $this->httpStatus());
        $order = $this->latestOrder();
        self::assertSame(OrderType::ADD_TRAFFIC, $order->getType());
        self::assertSame(10, $order->getMetadata()['trafficGb']);
        self::assertSame(25000, $order->getAmount());
    }

    public function testPayingExtraTrafficAddsItToTheService(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->appPostJson('/app/api/services/'.$this->service->getId().'/add-traffic', $session, ['trafficGb' => 10], self::newKey());
        $order = $this->latestOrder();
        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $this->methods['zibal']->getId()], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE) ?: '');
        $this->em->clear();
        $payment = $this->em->getRepository(Payment::class)->findOneBy(['order' => $order->getId()]) ?? throw new \LogicException();

        $result = self::getContainer()->get(PaymentSettlementService::class)->settle($payment, VerifiedPayment::fromGateway(new PaymentVerificationResult(success: true, paid: true, transactionId: 't', amount: '300000', currency: 'IRR')), 'test');
        self::assertTrue($result->isConfirmed(), $result->message);

        self::assertSame(60, (int) $this->row('vpn_service', (int) $this->service->getId())['traffic_limit_gb']);
        $this->appGet('/app/api/payments/'.$payment->getId().'/status', $session);
        self::assertSame(['state' => 'paid', 'final' => true, 'serviceId' => $this->service->getId()], $this->json());
    }

    public function testTheSwitchesAreRespected(): void
    {
        $session = $this->authenticate(self::CUSTOMER);
        $this->setting(SalesSettingsProvider::RENEWALS_ENABLED, 'false');
        $answer = $this->appPostJson('/app/api/services/'.$this->service->getId().'/renew', $session, [], self::newKey());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_closed', $answer['error']);

        $this->setting(SalesSettingsProvider::ADD_TRAFFIC_ENABLED, 'false');
        $answer = $this->appPostJson('/app/api/services/'.$this->service->getId().'/add-traffic', $session, ['trafficGb' => 10], self::newKey());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_closed', $answer['error']);

        $this->setting(SalesSettingsProvider::ADD_TRAFFIC_ENABLED, 'true');
        $this->setting('traffic_addon.enabled', 'false');
        $this->appPostJson('/app/api/services/'.$this->service->getId().'/add-traffic', $session, ['trafficGb' => 10], self::newKey());
        self::assertSame(409, $this->httpStatus(), 'the add-on itself switched off');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `order` WHERE type IN (?, ?)', [OrderType::RENEWAL, OrderType::ADD_TRAFFIC]));
    }

    public function testSomeoneElsesServiceCannotBeRenewedOrToppedUp(): void
    {
        $theirs = $this->createActiveService($this->createUser('8604'), $this->inbound);
        $session = $this->authenticate(self::CUSTOMER);
        $base = '/app/view/services/'.$theirs->getId();

        foreach ([$base.'/renew', $base.'/add-traffic'] as $view) {
            $this->appGet($view, $session);
            self::assertSame(404, $this->httpStatus(), $view);
        }
        foreach (['/renew', '/renew/quote', '/add-traffic', '/add-traffic/quote'] as $suffix) {
            $this->appPostJson('/app/api/services/'.$theirs->getId().$suffix, $session, ['trafficGb' => 10], self::newKey());
            self::assertSame(404, $this->httpStatus(), $suffix);
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `order` WHERE target_service_id = ?', [$theirs->getId()]));
    }

    private function latestOrder(): Order
    {
        $this->em->clear();

        return $this->em->getRepository(Order::class)->findOneBy(['user' => $this->user->getId()], ['id' => 'DESC']) ?? throw new \LogicException();
    }
}
