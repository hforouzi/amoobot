<?php

declare(strict_types=1);

namespace App\Tests\Payment\Contract;

use Amoobot\Plugin\DemoPaymentGateway\DemoPaymentGatewayPlugin;
use Amoobot\Plugin\NovinoPay\NovinoPayGatewayPlugin;
use Amoobot\Plugin\SwapWallet\SwapWalletGatewayPlugin;
use App\Entity\Payment;
use App\Entity\Plugin;
use App\Payment\Domain\Money\AmountMatcher;
use App\Payment\Domain\Money\AmountMatchStatus;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;

/**
 * The plugins shipped in docs/plugins must honour the verification contract
 * (docs/en/plugins/payment-gateway-plugin-sdk.md): report amount + currency, prefer stored references,
 * fail closed on webhooks.
 */
final class SamplePluginsContractTest extends IntegrationTestCase
{
    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3).'/docs/plugins';
        require_once $root.'/demo-payment-gateway/src/DemoPaymentGatewayPlugin.php';
        require_once $root.'/novinopay-gateway/src/NovinoPayGatewayPlugin.php';
        require_once $root.'/swapwallet-gateway/src/SwapWalletGatewayPlugin.php';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function paymentFor(string $code, array $config): Payment
    {
        $gateway = $this->createGateway('plugin', $config)->setPluginCode($code);
        $this->em->flush();
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000));

        return $this->createPayment($order, $gateway, PaymentStatus::PENDING);
    }

    private function adapter(PaymentGatewayPluginInterface $plugin, string $code, bool $testingShortcutsAllowed = false): PluginPaymentGatewayDriverAdapter
    {
        return new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode($code), $plugin, $testingShortcutsAllowed);
    }

    private function mockClient(): MockHttpClient
    {
        return new MockHttpClient($this->http);
    }

    public function testNovinoPayChargesRialAndReportsVerifiedAmount(): void
    {
        $payment = $this->paymentFor('novinopay', ['merchant_id' => 'm', 'api_base_url' => 'https://novino.test', 'callback_base_url' => 'https://shop.test']);
        $plugin = $this->adapter(new NovinoPayGatewayPlugin($this->mockClient()), 'novinopay');
        $this->http->onJson('/payment/ipg/v2/request', ['status' => '100', 'data' => ['authority' => 'AUTH1', 'trans_id' => 'T1', 'payment_url' => 'https://novino.test/pay/AUTH1']]);
        $this->http->onJson('/payment/ipg/v2/verification', ['status' => '100', 'data' => ['trans_id' => 'T1', 'ref_id' => 'R1']]);

        $created = $plugin->createPayment($payment, $payment->getOrder());
        $payment->setAuthority((string) $created->authority)->setGatewayTransactionId((string) $created->transactionId);
        $verification = $plugin->verifyPayment($payment, ['Authority' => 'FORGED-OTHER', 'PaymentStatus' => 'OK']);

        $sentCreate = json_decode((string) $this->http->requests('/v2/request')[0]['options']['body'], true);
        $sentVerify = json_decode((string) $this->http->requests('/v2/verification')[0]['options']['body'], true);
        self::assertSame(1500000, $sentCreate['amount'], 'Toman x 10');
        self::assertSame('AUTH1', $sentVerify['authority'], 'stored authority wins over the callback');
        self::assertSame(['1500000', 'IRR'], [$verification->amount, $verification->currency]);
        self::assertSame(AmountMatchStatus::MATCH, (new AmountMatcher())->matchVerification($payment, $verification)->status);
    }

    public function testSwapWalletReportsInvoiceAmountAgainstItsQuote(): void
    {
        $payment = $this->paymentFor('swapwallet', ['api_key' => 'k', 'api_base_url' => 'https://swap.test', 'toman_per_usd' => 50000, 'callback_base_url' => 'https://shop.test']);
        $payment->setGatewayTransactionId('INV1')->setExpectation('3', 'USD');
        $plugin = $this->adapter(new SwapWalletGatewayPlugin($this->mockClient()), 'swapwallet');
        $this->http->onJson('/v2/application/invoice/INV1', ['status' => 'OK', 'result' => ['id' => 'INV1', 'status' => 'PAID', 'amount' => ['number' => '3', 'unit' => 'USD']]]);

        $verification = $plugin->verifyPayment($payment, ['id' => 'SOMEONE-ELSES-INVOICE']);

        self::assertTrue($verification->paid);
        self::assertCount(1, $this->http->requests('/v2/application/invoice/INV1'), 'stored invoice id wins over the request');
        self::assertSame(AmountMatchStatus::MATCH, (new AmountMatcher())->matchVerification($payment, $verification)->status);
    }

    public function testSwapWalletWebhookFailsClosedWithoutSecret(): void
    {
        $payment = $this->paymentFor('swapwallet', ['api_key' => 'k', 'api_base_url' => 'https://swap.test']);
        $plugin = $this->adapter(new SwapWalletGatewayPlugin($this->mockClient()), 'swapwallet');
        $request = Request::create('/payment/webhook/plugin/swapwallet', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"id":"INV1","status":"PAID"}');

        $notification = $plugin->handleNotification($payment->getGateway() ?? throw new \LogicException(), $request, NotificationAwareGatewayInterface::CHANNEL_WEBHOOK);

        self::assertFalse($notification->authenticated);
        self::assertSame(401, $notification->rejectStatus);
    }

    public function testDemoPluginNeverAutoConfirmsByDefault(): void
    {
        $payment = $this->paymentFor('demo_payment_gateway', []);
        // A test installation (APP_ENV=test): testing shortcuts allowed. Production: DemoPluginProductionGuardTest.
        $plugin = $this->adapter(new DemoPaymentGatewayPlugin(), 'demo_payment_gateway', testingShortcutsAllowed: true);

        $verification = $plugin->verifyPayment($payment, ['demo_paid' => 'true']);

        self::assertTrue($verification->paid);
        self::assertSame(AmountMatchStatus::MISSING, (new AmountMatcher())->matchVerification($payment, $verification)->status, 'lands in NEEDS_REVIEW');

        $payment->getGateway()?->setConfig(['auto_confirm_for_testing' => true]);
        $testing = $plugin->verifyPayment($payment, ['demo_paid' => 'true']);
        self::assertSame(AmountMatchStatus::MATCH, (new AmountMatcher())->matchVerification($payment, $testing)->status);
    }
}
