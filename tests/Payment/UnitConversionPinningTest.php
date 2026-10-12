<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use Amoobot\Plugin\NovinoPay\NovinoPayGatewayPlugin;
use Amoobot\Plugin\SwapWallet\SwapWalletGatewayPlugin;
use App\Entity\Payment;
use App\Entity\Plugin;
use App\Payment\Domain\Money\AmountNormalizer;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\CustomApiGateway;
use App\Payment\Infrastructure\ManualCardGateway;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\ZibalGateway;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Pins how much every gateway charges for a 150,000 Toman plan. Shop amounts are Toman
 * (docs/en/payments/amount-units.md); a change here changes what customers pay, so it must be
 * deliberate.
 */
final class UnitConversionPinningTest extends IntegrationTestCase
{
    private const PRICE_TOMAN = 150_000;
    private const PRICE_RIAL = 1_500_000;
    private const TOMAN_PER_USD = 50_000; // => 3 USD

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2).'/docs/plugins';
        require_once $root.'/novinopay-gateway/src/NovinoPayGatewayPlugin.php';
        require_once $root.'/swapwallet-gateway/src/SwapWalletGatewayPlugin.php';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function payment(string $type, array $config, ?string $pluginCode = null): Payment
    {
        $gateway = $this->createGateway($type, $config)->setPluginCode($pluginCode);
        $this->em->flush();

        return $this->createPayment($this->createOrder($this->createUser(), $this->createPlan(self::PRICE_TOMAN)), $gateway, PaymentStatus::PENDING);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentJson(string $urlNeedle): array
    {
        $requests = $this->http->requests($urlNeedle);
        self::assertNotEmpty($requests, 'no request to '.$urlNeedle);

        return json_decode((string) $requests[0]['options']['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    public function testNormalizerRule(): void
    {
        self::assertSame(self::PRICE_RIAL, AmountNormalizer::toRial(self::PRICE_TOMAN));
        self::assertSame('IRT', AmountNormalizer::SHOP_CURRENCY);
    }

    public function testManualCardShowsTheTomanAmountUnchanged(): void
    {
        $payment = $this->payment(PaymentGatewayType::MANUAL_CARD, []);

        $result = self::getContainer()->get(ManualCardGateway::class)->createPayment($payment, $payment->getOrder());

        self::assertTrue($result->success);
        self::assertSame(self::PRICE_TOMAN, $payment->getPayableAmount(), 'the customer transfers the Toman amount shown by the bot');
    }

    public function testZibalChargesRial(): void
    {
        $payment = $this->payment(PaymentGatewayType::ZIBAL, ['merchant' => 'm', 'callback_base_url' => 'https://shop.test']);
        $this->http->onJson('gateway.zibal.ir/v1/request', ['result' => 100, 'trackId' => '1']);

        self::getContainer()->get(ZibalGateway::class)->createPayment($payment, $payment->getOrder());

        self::assertSame(self::PRICE_RIAL, $this->sentJson('/v1/request')['amount']);
    }

    public function testNowPaymentsQuotesUsdFromToman(): void
    {
        $payment = $this->payment(PaymentGatewayType::NOWPAYMENTS, ['api_key' => 'k', 'ipn_secret' => 's', 'callback_base_url' => 'https://shop.test', 'payment_mode' => 'invoice', 'price_currency' => 'usd', 'toman_per_usd' => self::TOMAN_PER_USD]);
        $this->http->onJson('/v1/invoice', ['id' => 'inv', 'invoice_url' => 'https://np.test/inv']);

        self::getContainer()->get(NowPaymentsGateway::class)->createPayment($payment, $payment->getOrder());

        self::assertEqualsWithDelta(3.0, (float) $this->sentJson('/v1/invoice')['price_amount'], 0.000001);
        self::assertSame(['3', 'USD'], [$payment->getExpectedAmount(), $payment->getExpectedCurrency()]);
    }

    public function testCustomApiTemplatesExposeTomanAndRial(): void
    {
        $payment = $this->payment(PaymentGatewayType::CUSTOM_API, ['create' => [
            'url' => 'https://psp.test/create',
            'method' => 'POST',
            'body' => ['toman' => '{{ payable_amount }}', 'rial' => '{{ payable_amount_rial }}'],
            'response_mapping' => ['success' => 'ok', 'payment_url' => 'url', 'transaction_id' => 'tx'],
        ]]);
        $this->http->onJson('psp.test/create', ['ok' => true, 'url' => 'https://psp.test/pay', 'tx' => 'T']);

        self::getContainer()->get(CustomApiGateway::class)->createPayment($payment, $payment->getOrder());

        $sent = $this->sentJson('psp.test/create');
        self::assertSame([(string) self::PRICE_TOMAN, (string) self::PRICE_RIAL], [(string) $sent['toman'], (string) $sent['rial']]);
    }

    public function testNovinoPayPluginChargesRial(): void
    {
        $payment = $this->payment('plugin', ['merchant_id' => 'm', 'api_base_url' => 'https://novino.test', 'callback_base_url' => 'https://shop.test'], 'novinopay');
        $this->http->onJson('/payment/ipg/v2/request', ['status' => '100', 'data' => ['authority' => 'A', 'trans_id' => 'T', 'payment_url' => 'https://novino.test/pay/A']]);
        $plugin = new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode('novinopay'), new NovinoPayGatewayPlugin(new MockHttpClient($this->http)));

        $plugin->createPayment($payment, $payment->getOrder());

        self::assertSame(self::PRICE_RIAL, $this->sentJson('/v2/request')['amount']);
    }

    public function testSwapWalletPluginQuotesUsdFromToman(): void
    {
        $payment = $this->payment('plugin', ['api_key' => 'k', 'api_base_url' => 'https://swap.test', 'callback_base_url' => 'https://shop.test', 'toman_per_usd' => self::TOMAN_PER_USD, 'amount_unit' => 'toman', 'price_currency' => 'USD', 'payment_mode' => 'invoice'], 'swapwallet');
        $this->http->onJson('/v2/application/invoice', ['status' => 'OK', 'result' => ['id' => 'INV', 'url' => 'https://swap.test/pay/INV']]);
        $plugin = new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode('swapwallet'), new SwapWalletGatewayPlugin(new MockHttpClient($this->http)));

        $plugin->createPayment($payment, $payment->getOrder());

        self::assertEqualsWithDelta(3.0, (float) $this->sentJson('/v2/application/invoice')['amount']['number'], 0.000001);
        self::assertSame('USD', $payment->getExpectedCurrency());
        self::assertEqualsWithDelta(3.0, (float) $payment->getExpectedAmount(), 0.000001);
    }
}
