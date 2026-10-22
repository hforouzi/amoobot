<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\DiscountCode;
use App\Entity\PaymentGateway;
use App\Entity\Plan;
use App\Entity\Setting;
use App\Entity\StorePaymentMethod;
use App\Entity\User;
use App\Entity\VpnInbound;
use App\Payment\Domain\PaymentGatewayType;

/**
 * A shop for Mini App purchase tests: one plan on the test panel and a store payment method per gateway type
 * (card to card, Zibal, custom API, NOWPayments), each answered by MockHttpResponder. For IntegrationTestCase.
 */
trait MiniAppShopFixtures
{
    protected const CUSTOMER = '8601';
    protected const CARD_NUMBER = '6037-9911-2233-4455';

    protected User $user;
    protected VpnInbound $inbound;
    protected Plan $plan;
    /** @var array<string, StorePaymentMethod> gateway type => method */
    protected array $methods = [];

    protected function setUpShop(): void
    {
        $connection = $this->em->getConnection();
        foreach (['mini_app_session', 'checkout_request', 'trial_claim', 'trial_plan', 'admin_audit_log', 'admin_user', 'required_channel'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement("DELETE FROM setting WHERE key_name LIKE 'sales.%' OR key_name LIKE 'traffic_addon.%' OR key_name LIKE 'payment.%'");

        $this->user = $this->createUser(self::CUSTOMER);
        $this->inbound = $this->createTestInbound();
        $this->plan = $this->createPlan(150000, $this->inbound);
        $this->addMethod(PaymentGatewayType::MANUAL_CARD, [], 'کارت به کارت', 10, static fn (PaymentGateway $g): PaymentGateway => $g->setManualCardNumber(self::CARD_NUMBER)->setManualCardHolder('Shop Owner'));
        $this->addMethod(PaymentGatewayType::ZIBAL, ['merchant' => 'test-merchant', 'callback_base_url' => 'https://shop.test'], 'زیبال', 20);
        $this->addMethod(PaymentGatewayType::CUSTOM_API, [
            'create' => [
                'url' => 'https://psp.test/create',
                'method' => 'POST',
                'body' => ['amount' => '{{ amount_rial }}', 'return' => '{{ return_url }}'],
                'response_mapping' => ['success' => 'ok', 'payment_url' => 'url', 'transaction_id' => 'tx'],
            ],
            'verify' => ['url' => 'https://psp.test/verify', 'method' => 'POST', 'body' => ['tx' => '{{ transaction_id }}'], 'response_mapping' => ['success' => 'ok', 'paid' => 'state', 'paid_values' => ['paid']]],
        ], 'درگاه اختصاصی', 30);
        $this->addMethod(PaymentGatewayType::NOWPAYMENTS, ['api_key' => 'np-key', 'ipn_secret' => 'np-secret', 'callback_base_url' => 'https://shop.test', 'payment_mode' => 'invoice', 'price_currency' => 'usd', 'toman_per_usd' => 50000], 'ارز دیجیتال', 40);
        $this->em->flush();
        $this->answerGateways();
    }

    /** The fake answers of every gateway (MockHttpResponder::reset() forgets them). */
    protected function answerGateways(): void
    {
        $this->http->onJson('gateway.zibal.ir/v1/request', ['result' => 100, 'trackId' => 'ZTRACK7', 'message' => 'success']);
        $this->http->onJson('psp.test/create', ['ok' => true, 'url' => 'https://psp.test/pay/CX1', 'tx' => 'CX1']);
        $this->http->onJson('/v1/invoice', ['id' => 'inv-77', 'invoice_url' => 'https://nowpayments.test/inv-77']);
        $this->http->onJson('/sendPhoto', ['ok' => true, 'result' => ['message_id' => 5, 'photo' => [['file_id' => 'AgAD-small', 'width' => 90], ['file_id' => 'AgAD-receipt', 'width' => 800]]]]);
    }

    /**
     * @param array<string, mixed>                          $config
     * @param (\Closure(PaymentGateway): PaymentGateway)|null $configure
     */
    protected function addMethod(string $type, array $config, string $title, int $sort, ?\Closure $configure = null): StorePaymentMethod
    {
        $gateway = $this->createGateway($type, $config, 'IRT');
        if (null !== $configure) {
            $configure($gateway);
        }
        $method = (new StorePaymentMethod())->setGateway($gateway)->setTitle($title)->setIsActive(true)->setSortOrder($sort);
        $this->em->persist($method);

        return $this->methods[$type] = $method;
    }

    protected function setting(string $key, string $value): void
    {
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['keyName' => $key]) ?? (new Setting())->setKeyName($key)->setType('string');
        $setting->setValue($value);
        $this->em->persist($setting);
        $this->em->flush();
    }

    protected function discountCode(string $code, string $type, int $value, ?\Closure $configure = null): DiscountCode
    {
        $discount = (new DiscountCode())->setCode($code)->setType($type)->setValue($value)->setIsActive(true);
        if (null !== $configure) {
            $configure($discount);
        }
        $this->em->persist($discount);
        $this->em->flush();

        return $discount;
    }

    protected function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
