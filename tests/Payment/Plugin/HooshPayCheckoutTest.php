<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use Amoobot\Plugin\HooshPay\HooshPayGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\StorePaymentMethod;
use App\Payment\Domain\GatewayPayableAmount;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\Checkout\PaymentStart;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\HooshPayFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * HooshPay in the checkout: offered only when enabled and configured and within its amount limits, one invoice per
 * payment row (a replaced row gets its invoice cancelled, best effort), HooshPay's payable_amount shown to the customer,
 * and a friendly text when HooshPay is unavailable.
 */
final class HooshPayCheckoutTest extends IntegrationTestCase
{
    use HooshPayFixtures;
    use MiniAppShopFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installHooshPay();
        self::getContainer()->get(RecordingLogger::class)->records = [];
    }

    private function starter(): PaymentStarter
    {
        return self::getContainer()->get(PaymentStarter::class);
    }

    private function topUp(int $amount = self::HOOSH_AMOUNT): Order
    {
        $order = (new Order())->setUser($this->createUser())->setType(OrderType::WALLET_TOPUP)->setAmount($amount)->setStatus(OrderStatus::WAITING_PAYMENT)->setTrackingCode('TOPUP-1');
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function method(array $overrides = []): StorePaymentMethod
    {
        return $this->hooshPayMethod($this->hooshPayGateway($overrides));
    }

    /** @return list<int|null> the gateway ids offered for the order */
    private function offered(Order $order): array
    {
        return array_map(static fn (StorePaymentMethod $m): ?int => $m->getGateway()->getId(), $this->starter()->methodsFor($order));
    }

    /** @return array<string, mixed> */
    private function paymentRow(Payment $payment): array
    {
        return $this->row('payment', (int) $payment->getId());
    }

    public function testOfferedOnlyWhenEnabledConfiguredAndWithinTheLimits(): void
    {
        $order = $this->topUp(150_000);
        $off = $this->method(['enabled' => false]);
        $http = $this->method(['callback_base_url' => 'http://shop.example']);
        $tooLow = $this->method(['min_amount' => 999]);
        $narrow = $this->method(['min_amount' => 200_000]);
        $on = $this->method(['max_amount' => 150_000]);

        $offered = $this->offered($order);

        self::assertContains($on->getGateway()->getId(), $offered);
        foreach ([$off, $http, $tooLow, $narrow] as $method) {
            self::assertNotContains($method->getGateway()->getId(), $offered);
        }
        self::assertSame([], $this->hooshPayCalls(), 'listing never calls HooshPay');
    }

    public function testADisabledPluginIsNotOffered(): void
    {
        $order = $this->topUp();
        $method = $this->method();
        $this->em->getConnection()->executeStatement("UPDATE plugin SET status = 'disabled' WHERE code = 'hooshpay'");
        $this->em->clear();

        self::assertNotContains($method->getGateway()->getId(), $this->offered($this->em->find(Order::class, $order->getId()) ?? throw new \LogicException()));
    }

    public function testStartingCreatesOneInvoiceAndShowsThePayableAmount(): void
    {
        $order = $this->topUp();
        $method = $this->method(['fee_mode' => 'buyer']);
        $this->hooshPayAnswers([self::hooshCreated('inv_A', self::HOOSH_AMOUNT, 180_017)]);

        $start = $this->starter()->start($order, $method);

        self::assertTrue($start->isReady(), (string) $start->message);
        $payment = $start->payment ?? throw new \LogicException();
        self::assertSame('inv_A', $payment->getGatewayTransactionId());
        self::assertSame('https://hooshpay.xyz/pay/inv_A', $payment->getPaymentUrl());
        self::assertSame(180_017, GatewayPayableAmount::of($payment));
        self::assertStringContainsString('180,017 تومان', GatewayPayableAmount::botLine($payment));
        self::assertSame((string) self::HOOSH_AMOUNT, $payment->getExpectedAmount());
        $calls = $this->hooshPayCalls();
        self::assertSame(self::HOOSH_AMOUNT, $calls[0]['body']['amount']);
        self::assertSame('buyer', $calls[0]['body']['fee_mode']);
        self::assertSame('TOPUP-1-P'.$payment->getId(), $calls[0]['body']['order_id']);
        $stored = (string) json_encode($this->paymentRow($payment), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('6037991234567890', $stored, 'the card object is never stored');
        self::assertStringNotContainsString('Card Holder', $stored);
        self::assertStringNotContainsString(self::HOOSH_KEY, $stored);

        // Choosing HooshPay again: the same invoice, no second create.
        $again = $this->starter()->start($order, $method);
        self::assertSame($payment->getId(), $again->payment?->getId());
        self::assertCount(1, $this->hooshPayCalls());
    }

    public function testRestartingWithANewAmountClosesTheOldRowAndCancelsItsInvoice(): void
    {
        $order = $this->topUp();
        $method = $this->method();
        $this->hooshPayAnswers([
            self::hooshCreated('inv_A', self::HOOSH_AMOUNT, self::HOOSH_AMOUNT + 11),
            self::hooshCreated('inv_B', 120_000, 120_013),
            ['status' => 200, 'body' => ['success' => true]],
        ]);
        $first = $this->starter()->start($order, $method)->payment ?? throw new \LogicException();

        $order->setAmount(120_000);
        $this->em->flush();
        $second = $this->starter()->start($order, $method);

        self::assertTrue($second->isReady());
        self::assertNotSame($first->getId(), $second->payment?->getId());
        self::assertSame('inv_B', $second->payment?->getGatewayTransactionId());
        $old = $this->paymentRow($first);
        self::assertSame(PaymentStatus::REJECTED, $old['status']);
        self::assertSame(Payment::SOURCE_GATEWAY_INVOICE_SUPERSEDED, $old['confirmation_source']);
        self::assertSame('inv_A', $old['gateway_transaction_id'], 'the closed row keeps its reference for late money');
        $calls = $this->hooshPayCalls();
        self::assertCount(3, $calls);
        self::assertSame('POST', $calls[2]['method']);
        self::assertSame('https://hooshpay.xyz/api/v1/invoices/inv_A/cancel', $calls[2]['url']);
        self::assertStringContainsString('Superseded invoice cancelled at the gateway.', self::getContainer()->get(RecordingLogger::class)->text());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function cancelFailures(): iterable
    {
        yield 'not pending any more' => [['status' => 400, 'body' => ['success' => false]], 'http_400'];
        yield 'unavailable' => [['status' => 503, 'body' => []], 'http_503'];
        yield 'timeout' => [['transport' => 'Idle timeout reached for "https://hooshpay.xyz/api/v1/invoices/inv_A/cancel"'], 'timeout'];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('cancelFailures')]
    public function testAFailedCancelIsLoggedAndNotFatal(array $answer, string $outcome): void
    {
        $order = $this->topUp();
        $method = $this->method();
        $this->hooshPayAnswers([self::hooshCreated('inv_A', self::HOOSH_AMOUNT, self::HOOSH_AMOUNT + 11), self::hooshCreated('inv_B', 120_000, 120_013), $answer]);
        $first = $this->starter()->start($order, $method)->payment ?? throw new \LogicException();
        $order->setAmount(120_000);
        $this->em->flush();

        $second = $this->starter()->start($order, $method);

        self::assertTrue($second->isReady());
        self::assertSame(PaymentStatus::REJECTED, $this->paymentRow($first)['status']);
        $logger = self::getContainer()->get(RecordingLogger::class);
        $warnings = array_values(array_filter($logger->records, static fn (array $r): bool => 'Superseded invoice not cancelled at the gateway.' === $r['message']));
        self::assertCount(1, $warnings);
        self::assertSame($outcome, $warnings[0]['context']['outcome']);
        self::assertSame($first->getId(), $warnings[0]['context']['payment_id']);
        self::assertStringNotContainsString(self::HOOSH_KEY, $logger->text());
        self::assertStringNotContainsString(self::HOOSH_SECRET, $logger->text());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unavailable(): iterable
    {
        yield '503' => [['status' => 503, 'body' => ['success' => false]]];
        yield 'timeout' => [['transport' => 'Idle timeout reached for "https://hooshpay.xyz/api/v1/invoices"']];
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('unavailable')]
    public function testUnavailabilityIsAFriendlyMessageWithoutRetries(array $answer): void
    {
        $order = $this->topUp();
        $this->hooshPayAnswers([$answer]);

        $start = $this->starter()->start($order, $this->method());

        self::assertFalse($start->isReady());
        self::assertSame(PaymentStart::KIND_ONLINE, $start->kind);
        self::assertSame(HooshPayGatewayPlugin::CUSTOMER_UNAVAILABLE, $start->message, 'the bot shows this text as is');
        self::assertCount(1, $this->hooshPayCalls(), 'no retry');
    }

    public function testTheMiniAppShowsTheFriendlyMessageAndThePayableAmount(): void
    {
        $this->setUpShop();
        $this->startMiniAppClient();
        $method = $this->method();
        $session = $this->authenticate(self::CUSTOMER);
        $order = $this->createOrder($this->user, $this->plan);

        $this->hooshPayAnswers([['status' => 503, 'body' => []]]);
        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $method->getId()], self::newKey());
        self::assertSame('payment_start_failed', $answer['error'] ?? null);
        self::assertSame(HooshPayGatewayPlugin::CUSTOMER_UNAVAILABLE, $answer['message'] ?? null);

        $this->hooshPayAnswers([self::hooshCreated('inv_M', 150_000, 150_019, ['mini_app_link' => 'https://t.me/SomeBot/app?startapp=x'])]);
        $answer = $this->appPostJson('/app/api/orders/'.$order->getId().'/pay', $session, ['methodId' => $method->getId()], self::newKey());
        self::assertArrayHasKey('redirect', $answer, (string) json_encode($answer, JSON_UNESCAPED_UNICODE));
        $page = $this->appGet((string) $answer['redirect'], $session);
        self::assertStringContainsString('150,019', $page);
        self::assertStringContainsString('https://hooshpay.xyz/pay/inv_M', $page);
        self::assertStringContainsString('data-open-link', $page, 'opened with openLink, not openTelegramLink');
        self::assertStringNotContainsString('data-telegram-link', $page);
    }
}
