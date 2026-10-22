<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Entity\DiscountCode;
use App\Entity\Order;
use App\Entity\OrderDraft;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plan;
use App\Entity\Setting;
use App\Entity\StorePaymentMethod;
use App\Entity\TrialPlan;
use App\Entity\User;
use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Shop\Application\SalesSettingsProvider;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\BotTranscript;
use App\Tests\Support\IntegrationTestCase;

/**
 * The customer's purchase flows in the bot, recorded end to end: every Telegram call (texts, buttons) is compared to
 * a transcript snapshot and the resulting orders/payments are checked. Written before the checkout flow moved into
 * src/Shop/Application/Checkout, to prove the move changed nothing a customer or admin sees.
 */
final class BotCheckoutCharacterizationTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private const CUSTOMER = '731100';

    private User $user;
    private VpnInbound $inbound;
    private Plan $plan;
    private StorePaymentMethod $card;
    private StorePaymentMethod $zibal;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        foreach (['trial_claim', 'trial_plan', 'admin_audit_log', 'admin_user', 'required_channel'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement("DELETE FROM setting WHERE key_name LIKE 'sales.%' OR key_name LIKE 'traffic_addon.%' OR key_name LIKE 'payment.%'");

        $this->user = $this->createUser(self::CUSTOMER);
        $this->inbound = $this->createTestInbound();
        $this->plan = $this->createPlan(150000, $this->inbound);
        $cardGateway = $this->createGateway(PaymentGatewayType::MANUAL_CARD, [], 'IRT')->setManualCardNumber('6037-0000-1111-2222')->setManualCardHolder('Shop Owner');
        $zibalGateway = $this->createGateway(PaymentGatewayType::ZIBAL, ['merchant' => 'test-merchant', 'callback_base_url' => 'https://shop.test'], 'IRT');
        $this->card = $this->method($cardGateway, 'کارت به کارت', 10);
        $this->zibal = $this->method($zibalGateway, 'زیبال', 20);
        $this->http->onJson('gateway.zibal.ir/v1/request', ['result' => 100, 'trackId' => 'ZTRACK1', 'message' => 'success']);
        $this->em->flush();
        $this->http->reset();
        $this->http->onJson('gateway.zibal.ir/v1/request', ['result' => 100, 'trackId' => 'ZTRACK1', 'message' => 'success']);
    }

    protected function tearDown(): void
    {
        // Trial rows point at this test's inbound, which the next test's purge deletes.
        $this->em->getConnection()->executeStatement('DELETE FROM trial_claim');
        $this->em->getConnection()->executeStatement('DELETE FROM trial_plan');
        parent::tearDown();
    }

    public function testNewServiceWithManualCardAndReceipt(): void
    {
        $this->pressButton(self::CUSTOMER, 'buy_service');
        $this->pressButton(self::CUSTOMER, 'select_plan:'.$this->plan->getId());
        $this->sendText(self::CUSTOMER, 'Sara_Shop');
        $draft = $this->latestDraft();
        $this->pressButton(self::CUSTOMER, 'custom_order_confirm:'.$draft->getId());
        $order = $this->latestOrder();
        $this->pressButton(self::CUSTOMER, 'discount_skip_order:'.$order->getId());
        $this->pressButton(self::CUSTOMER, 'select_store_payment_method:'.$order->getId().':'.$this->card->getId());
        $payment = $this->latestPayment();
        $this->pressButton(self::CUSTOMER, 'payment_submit_receipt:'.$payment->getId());
        $this->sendPhoto(self::CUSTOMER, 'RECEIPT-FILE-1');

        $this->em->clear();
        $order = $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException();
        $payment = $this->em->find(Payment::class, $payment->getId()) ?? throw new \LogicException();
        self::assertSame(OrderType::NEW_SERVICE, $order->getType());
        self::assertSame(OrderStatus::WAITING_PAYMENT, $order->getStatus());
        self::assertSame(150000, $order->getAmount());
        self::assertSame(PaymentStatus::SUBMITTED, $payment->getStatus());
        self::assertSame('RECEIPT-FILE-1', $payment->getReceiptFileId());
        self::assertSame(150000, $payment->getAmount());
        self::assertSame(PaymentGatewayType::MANUAL_CARD, $payment->getGatewayType());
        self::assertStringStartsWith('sara_shop_', (string) ($order->getMetadata()['finalUsername'] ?? ''));
        self::assertSame(['baseAmount' => 150000, 'globalDiscountPercent' => 0, 'globalDiscountAmount' => 0, 'afterGlobalDiscountAmount' => 150000, 'discountCode' => null, 'discountCodeAmount' => 0, 'finalAmount' => 150000, 'planPriceSource' => 'current_plan'], $order->getMetadata()['priceSnapshot']);
        $this->snapshot('new-service-manual-card', $order, $payment);
    }

    public function testNewServiceWithADiscountCodeAndZibal(): void
    {
        $this->discountCode('OFF20', DiscountCode::TYPE_PERCENT, 20);
        $this->pressButton(self::CUSTOMER, 'select_plan:'.$this->plan->getId());
        $this->sendText(self::CUSTOMER, 'nima');
        $this->pressButton(self::CUSTOMER, 'custom_order_confirm:'.$this->latestDraft()->getId());
        $order = $this->latestOrder();
        $this->pressButton(self::CUSTOMER, 'discount_enter_order:'.$order->getId());
        $this->sendText(self::CUSTOMER, 'off20');
        $this->pressButton(self::CUSTOMER, 'select_store_payment_method:'.$order->getId().':'.$this->zibal->getId());

        $this->em->clear();
        $order = $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException();
        $payment = $this->latestPayment();
        self::assertSame(120000, $order->getAmount());
        self::assertSame('OFF20', $order->getMetadata()['priceSnapshot']['discountCode']);
        self::assertSame(30000, $order->getMetadata()['priceSnapshot']['discountCodeAmount']);
        self::assertSame(120000, $payment->getAmount());
        self::assertSame('https://gateway.zibal.ir/start/ZTRACK1', $payment->getPaymentUrl());
        $this->snapshot('new-service-discount-zibal', $order, $payment);
    }

    public function testAnInvalidDiscountCodeKeepsThePrice(): void
    {
        $this->pressButton(self::CUSTOMER, 'select_plan:'.$this->plan->getId());
        $this->sendText(self::CUSTOMER, 'nima');
        $this->pressButton(self::CUSTOMER, 'custom_order_confirm:'.$this->latestDraft()->getId());
        $order = $this->latestOrder();
        $this->pressButton(self::CUSTOMER, 'discount_enter_order:'.$order->getId());
        $this->sendText(self::CUSTOMER, 'NOPE');

        $this->em->clear();
        self::assertSame(150000, $this->em->find(Order::class, $order->getId())?->getAmount());
        $this->snapshot('new-service-invalid-discount', $order);
    }

    public function testACustomizablePlanAsksTrafficAndDuration(): void
    {
        $this->plan->setIsCustomizable(true)->setMinTrafficGb(10)->setMaxTrafficGb(100)->setPricePerGb(2000)->setMinDurationDays(30)->setMaxDurationDays(90)->setPricePerDay(1000);
        $this->em->flush();

        $this->pressButton(self::CUSTOMER, 'select_plan:'.$this->plan->getId());
        $this->sendText(self::CUSTOMER, 'custom_user');
        $this->sendText(self::CUSTOMER, '5');
        $this->sendText(self::CUSTOMER, '40');
        $this->sendText(self::CUSTOMER, '200');
        $this->sendText(self::CUSTOMER, '60');
        $draft = $this->latestDraft();
        $this->pressButton(self::CUSTOMER, 'custom_order_confirm:'.$draft->getId());
        $order = $this->latestOrder();

        self::assertSame(40, $order->getMetadata()['trafficGb']);
        self::assertSame(60, $order->getMetadata()['durationDays']);
        $this->snapshot('new-service-customizable', $order);
    }

    public function testRenewalWithManualCard(): void
    {
        $service = $this->ownedService();
        $this->pressButton(self::CUSTOMER, 'service_renew:'.$service->getId());
        $this->pressButton(self::CUSTOMER, 'renewal_confirm:'.$service->getId());
        $draft = $this->latestDraft();
        $this->pressButton(self::CUSTOMER, 'discount_skip:'.$draft->getId());
        $order = $this->latestOrder();
        $this->pressButton(self::CUSTOMER, 'select_store_payment_method:'.$order->getId().':'.$this->card->getId());

        $this->em->clear();
        $order = $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException();
        self::assertSame(OrderType::RENEWAL, $order->getType());
        self::assertSame($service->getId(), $order->getTargetService()?->getId());
        self::assertSame(PaymentStatus::PENDING, $this->latestPayment()->getStatus());
        $this->snapshot('renewal-manual-card', $order, $this->latestPayment(), $service);
    }

    public function testAddTrafficWithADiscountCodeAndZibal(): void
    {
        $this->setting('traffic_addon.price_per_gb', '3000');
        $this->discountCode('GB5', DiscountCode::TYPE_FIXED, 5000);
        $service = $this->ownedService();
        $this->pressButton(self::CUSTOMER, 'service_add_traffic_order:'.$service->getId());
        $this->sendText(self::CUSTOMER, '500');
        $this->sendText(self::CUSTOMER, '10');
        $draft = $this->latestDraft();
        $this->pressButton(self::CUSTOMER, 'add_traffic_confirm:'.$draft->getId());
        $this->pressButton(self::CUSTOMER, 'discount_enter:'.$draft->getId());
        $this->sendText(self::CUSTOMER, 'gb5');
        $order = $this->latestOrder();
        $this->pressButton(self::CUSTOMER, 'select_store_payment_method:'.$order->getId().':'.$this->zibal->getId());

        $this->em->clear();
        $order = $this->em->find(Order::class, $order->getId()) ?? throw new \LogicException();
        self::assertSame(OrderType::ADD_TRAFFIC, $order->getType());
        self::assertSame(10, $order->getMetadata()['trafficGb']);
        self::assertSame(25000, $order->getAmount());
        self::assertSame('https://gateway.zibal.ir/start/ZTRACK1', $this->latestPayment()->getPaymentUrl());
        $this->snapshot('add-traffic-discount-zibal', $order, $this->latestPayment(), $service);
    }

    public function testResumeAndCancelAnIncompleteOrder(): void
    {
        $this->pressButton(self::CUSTOMER, 'select_plan:'.$this->plan->getId());
        $this->sendText(self::CUSTOMER, 'nima');
        $this->pressButton(self::CUSTOMER, 'custom_order_confirm:'.$this->latestDraft()->getId());
        $order = $this->latestOrder();
        $this->pressButton(self::CUSTOMER, 'discount_skip_order:'.$order->getId());
        $this->pressButton(self::CUSTOMER, 'select_store_payment_method:'.$order->getId().':'.$this->card->getId());
        $this->pressButton(self::CUSTOMER, 'order_resume:'.$order->getId());
        $this->pressButton(self::CUSTOMER, 'order_cancel:'.$order->getId());

        $this->em->clear();
        self::assertSame(OrderStatus::CANCELLED, $this->em->find(Order::class, $order->getId())?->getStatus());
        self::assertSame(PaymentStatus::REJECTED, $this->latestPayment()->getStatus());
        $this->snapshot('resume-and-cancel', $order, $this->latestPayment());
    }

    public function testSalesSwitchesAndMissingPaymentMethods(): void
    {
        $service = $this->ownedService();
        $this->setting(SalesSettingsProvider::NEW_ORDERS_ENABLED, 'false');
        $this->setting(SalesSettingsProvider::RENEWALS_ENABLED, 'false');
        $this->setting(SalesSettingsProvider::ADD_TRAFFIC_ENABLED, 'false');
        $this->pressButton(self::CUSTOMER, 'buy_service');
        $this->pressButton(self::CUSTOMER, 'select_plan:'.$this->plan->getId());
        $this->pressButton(self::CUSTOMER, 'service_renew:'.$service->getId());
        $this->pressButton(self::CUSTOMER, 'service_add_traffic_order:'.$service->getId());

        $this->setting(SalesSettingsProvider::NEW_ORDERS_ENABLED, 'true');
        $this->card->setIsActive(false);
        $this->zibal->setIsActive(false);
        $this->em->flush();
        $this->pressButton(self::CUSTOMER, 'select_plan:'.$this->plan->getId());
        $this->sendText(self::CUSTOMER, 'nima');
        $this->pressButton(self::CUSTOMER, 'custom_order_confirm:'.$this->latestDraft()->getId());
        $this->pressButton(self::CUSTOMER, 'discount_skip_order:'.$this->latestOrder()->getId());

        $this->snapshot('sales-closed-and-no-methods', $this->latestOrder(), null, $service);
    }

    public function testTrialClaim(): void
    {
        $trial = (new TrialPlan())->setTitle('تست یک روزه')->setIsActive(true)->setDurationDays(1)->setTrafficGb(1)->setInbound($this->inbound)->setMaxClaimsPerUser(1);
        $this->em->persist($trial);
        $this->em->flush();

        $this->pressButton(self::CUSTOMER, 'trial_account');
        $this->pressButton(self::CUSTOMER, 'trial_account');

        $service = $this->em->getRepository(VpnService::class)->findOneBy(['user' => $this->user]);
        self::assertInstanceOf(VpnService::class, $service);
        $this->snapshot('trial', null, null, $service);
    }

    private function snapshot(string $name, ?Order $order = null, ?Payment $payment = null, ?VpnService $service = null): void
    {
        $replace = [];
        if (null !== $order) {
            $order = $this->em->find(Order::class, $order->getId()) ?? $order;
            $replace[(string) $order->getTrackingCode()] = 'TRACKING';
            $username = (string) ($order->getMetadata()['finalUsername'] ?? '');
            if ('' !== $username) {
                $replace[$username] = 'FINAL_USERNAME';
            }
            $replace[':'.$order->getId()] = ':ORDER';
            $replace['#'.$order->getId()] = '#ORDER';
            $replace['Order ID: '.$order->getId()] = 'Order ID: ORDER';
            $draftId = (int) ($order->getMetadata()['orderDraftId'] ?? 0);
            if ($draftId > 0) {
                $replace[':'.$draftId] = ':DRAFT';
            }
        }
        foreach ($this->em->getRepository(OrderDraft::class)->findBy(['user' => $this->user]) as $draft) {
            $replace[':'.$draft->getId()] = ':DRAFT';
        }
        if (null !== $payment) {
            $replace[':'.$payment->getId()] = ':PAYMENT';
            $replace['Payment ID: '.$payment->getId()] = 'Payment ID: PAYMENT';
        }
        if (null !== $service) {
            $replace[':'.$service->getId()] = ':SERVICE';
            $replace['#'.$service->getId()] = '#SERVICE';
            $replace['Service ID: '.$service->getId()] = 'Service ID: SERVICE';
            $replace['شناسه سرویس: '.$service->getId()] = 'شناسه سرویس: SERVICE';
            foreach (array_filter([(string) $service->getUsername(), (string) $service->getRemoteId(), (string) $service->getSubscriptionUrl(), (string) $service->getClientUuid(), (string) $service->getPublicToken()]) as $value) {
                $replace[$value] = 'SERVICE_VALUE';
            }
        }
        $replace[':'.$this->plan->getId()] = ':PLAN';
        $replace[':'.$this->card->getId()] = ':CARD_METHOD';
        $replace[':'.$this->zibal->getId()] = ':ZIBAL_METHOD';

        BotTranscript::assertMatchesSnapshot('checkout-'.$name, BotTranscript::lines($this->http->requests(), $replace));
    }

    private function sendPhoto(string $telegramId, string $fileId): void
    {
        self::getContainer()->get(\App\Bot\Application\TelegramUpdateHandler::class)->handle([
            'update_id' => random_int(1, PHP_INT_MAX),
            'message' => [
                'message_id' => random_int(1, 100000),
                'from' => ['id' => (int) $telegramId, 'is_bot' => false, 'first_name' => 'T'.$telegramId],
                'chat' => ['id' => (int) $telegramId, 'type' => 'private'],
                'date' => time(),
                'photo' => [['file_id' => $fileId.'-small', 'width' => 90, 'height' => 90], ['file_id' => $fileId, 'width' => 800, 'height' => 800]],
            ],
        ]);
    }

    private function ownedService(): VpnService
    {
        $service = $this->createActiveService($this->user, $this->inbound);
        $service->setOrder($this->createOrder($this->user, $this->plan));
        $this->em->getConnection()->executeStatement('UPDATE `order` SET status = ? WHERE id = ?', [OrderStatus::PROVISIONED, $service->getOrder()?->getId()]);
        $this->em->flush();
        $this->em->refresh($service->getOrder() ?? throw new \LogicException());
        $this->http->reset();
        $this->http->onJson('gateway.zibal.ir/v1/request', ['result' => 100, 'trackId' => 'ZTRACK1', 'message' => 'success']);

        return $service;
    }

    private function method(PaymentGateway $gateway, string $title, int $sort): StorePaymentMethod
    {
        $method = (new StorePaymentMethod())->setGateway($gateway)->setTitle($title)->setIsActive(true)->setSortOrder($sort);
        $this->em->persist($method);

        return $method;
    }

    private function discountCode(string $code, string $type, int $value): void
    {
        $this->em->persist((new DiscountCode())->setCode($code)->setType($type)->setValue($value)->setIsActive(true));
        $this->em->flush();
    }

    private function setting(string $key, string $value): void
    {
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['keyName' => $key]) ?? (new Setting())->setKeyName($key)->setType('string');
        $setting->setValue($value);
        $this->em->persist($setting);
        $this->em->flush();
    }

    private function latestDraft(): OrderDraft
    {
        $this->em->clear();

        return $this->em->getRepository(OrderDraft::class)->findOneBy([], ['id' => 'DESC']) ?? throw new \LogicException('no draft');
    }

    private function latestOrder(): Order
    {
        $this->em->clear();

        return $this->em->getRepository(Order::class)->findOneBy(['user' => $this->user->getId()], ['id' => 'DESC']) ?? throw new \LogicException('no order');
    }

    private function latestPayment(): Payment
    {
        $this->em->clear();

        return $this->em->getRepository(Payment::class)->findOneBy([], ['id' => 'DESC']) ?? throw new \LogicException('no payment');
    }
}
