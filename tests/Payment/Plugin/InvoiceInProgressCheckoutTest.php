<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use Amoobot\Plugin\ConstrainedGateway\InvoicingGatewayPlugin;
use App\Entity\Payment;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ConstrainedGatewayFixture;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;

/**
 * The "try again" answer through the real checkouts: the customer reads it in the bot and the Mini App as any refused
 * start, with no change to either, and the next try works.
 */
final class InvoiceInProgressCheckoutTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ConstrainedGatewayFixture;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    private StorePaymentMethod $invoiceMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $this->installConstrainedPlugin(InvoicingGatewayPlugin::class);
        $this->invoiceMethod = $this->constrainedMethod();
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->tearDownConstrainedGateway();
        parent::tearDown();
    }

    /** An order whose invoice another start is creating right now. */
    private function reservedPayment(): Payment
    {
        $order = $this->createOrder($this->user, $this->plan);
        $payment = $this->createPayment($order, $this->invoiceMethod->getGateway(), PaymentStatus::PENDING);
        $payment->setExpiresAt(new \DateTimeImmutable('+60 seconds'));
        $this->em->flush();

        return $payment;
    }

    private function freeRow(Payment $payment): void
    {
        $this->em->getConnection()->executeStatement('UPDATE payment SET expires_at = NULL WHERE id = ?', [$payment->getId()]);
    }

    public function testTheBotAsksToTryAgain(): void
    {
        $payment = $this->reservedPayment();
        $data = 'select_store_payment_method:'.$payment->getOrder()->getId().':'.$this->invoiceMethod->getId();
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, $data);

        self::assertSame(0, InvoicingGatewayPlugin::$calls);
        $alerts = $this->http->requests('answerCallbackQuery');
        self::assertCount(1, $alerts);
        self::assertSame(PaymentStarter::INVOICE_IN_PROGRESS_MESSAGE, $this->requestBody($alerts[0])['text'] ?? null);
        self::assertSame([], $this->http->requests('sendMessage'), 'no payment link');

        $this->freeRow($payment);
        $this->http->reset();
        $this->pressButton(self::CUSTOMER, $data);

        self::assertSame(1, InvoicingGatewayPlugin::$calls);
        self::assertStringContainsString('provider.test', (string) json_encode($this->requestBody($this->http->requests('sendMessage')[0])));
    }

    public function testTheBotShowsNoCardDetailsWhenTheChosenMethodIsCardToCard(): void
    {
        $payment = $this->reservedPayment();
        $this->http->reset();

        $this->pressButton(self::CUSTOMER, 'select_store_payment_method:'.$payment->getOrder()->getId().':'.$this->methods[PaymentGatewayType::MANUAL_CARD]->getId());

        self::assertFalse($this->sentTextContains(self::CARD_NUMBER));
    }

    public function testTheMiniAppAsksToTryAgainAndTheNextTapWorks(): void
    {
        $payment = $this->reservedPayment();
        $path = '/app/api/orders/'.$payment->getOrder()->getId().'/pay';
        $session = $this->authenticate(self::CUSTOMER);

        $answer = $this->appPostJson($path, $session, ['methodId' => $this->invoiceMethod->getId()], self::newKey());

        self::assertSame(502, $this->httpStatus());
        self::assertSame(PaymentStarter::INVOICE_IN_PROGRESS_MESSAGE, $answer['message'] ?? null);

        $this->freeRow($payment);
        $answer = $this->appPostJson($path, $session, ['methodId' => $this->invoiceMethod->getId()], self::newKey());

        self::assertSame(200, $this->httpStatus());
        self::assertSame('/app/view/payments/'.$payment->getId(), $answer['redirect'] ?? null);
        self::assertSame(1, InvoicingGatewayPlugin::$calls);
    }
}
