<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use Amoobot\Plugin\SwapWallet\SwapWalletGatewayPlugin;
use Amoobot\Plugin\Winapay\WinapayGatewayPlugin;
use App\Entity\Payment;
use App\Entity\Plugin;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Infrastructure\CustomApiGateway;
use App\Payment\Infrastructure\NowPaymentsGateway;
use App\Payment\Infrastructure\PaymentGatewayRegistry;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use App\Tests\Support\BrandDomainEnvironment;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The customer's way back follows the brand; everything a gateway calls stays on the operational host. Winapay has one
 * CallbackURL: it stays put, and the core sends the browser on (303) with the opaque return token and nothing else.
 */
final class BrandPaymentReturnTest extends IntegrationTestCase
{
    use BrandDomainEnvironment;

    private const CALLBACK_BASE = self::OPERATIONAL_BASE;

    private KernelBrowser $client;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2).'/docs/plugins';
        require_once $root.'/swapwallet-gateway/src/SwapWalletGatewayPlugin.php';
        foreach (['WinapayException', 'WinapayStatus', 'WinapayApi', 'WinapayGatewayPlugin'] as $class) {
            require_once $root.'/winapay-gateway/src/'.$class.'.php';
        }
    }

    protected function setUp(): void
    {
        // The kernel boots in start(): each test chooses its own environment.
    }

    private function start(?string $brand = self::BRAND_BASE): void
    {
        $this->useBrandDomain($brand);
        parent::setUp();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreBrandDomain();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pendingPayment(string $type, array $config, ?string $pluginCode = null): Payment
    {
        $gateway = $this->createGateway($type, $config);
        if (null !== $pluginCode) {
            $gateway->setPluginCode($pluginCode);
        }
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000, $this->createTestInbound()));
        $payment = $this->createPayment($order, $gateway, PaymentStatus::PENDING);
        $payment->ensureReturnToken();
        $this->em->flush();

        return $payment;
    }

    private function postWinapayCallback(Payment $payment): void
    {
        $this->client->request('POST', self::OPERATIONAL_BASE.'/payment/callback/plugin/winapay?rt='.$payment->getReturnToken(), [
            'Authority' => 'A0000000000000000000000000000001',
            'PaymentStatus' => 'OK',
        ]);
    }

    public function testWinapayCallbackRedirectsToTheBrandReturnPageWithOnlyTheToken(): void
    {
        $this->start();
        $payment = $this->pendingPayment('plugin', ['merchant_id' => 'm', 'callback_base_url' => self::CALLBACK_BASE], 'winapay');

        $this->postWinapayCallback($payment);

        $response = $this->client->getResponse();
        self::assertSame(303, $response->getStatusCode());
        self::assertSame(self::BRAND_BASE.'/payment/return/'.$payment->getReturnToken(), $response->headers->get('Location'), 'no Authority, no status, no amount');
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function noBrand(): iterable
    {
        yield 'empty' => [null];
        yield 'invalid' => ['http://brand.example.org'];
    }

    #[DataProvider('noBrand')]
    public function testWithoutABrandTheRedirectIsTheRelativeOneOfToday(?string $brand): void
    {
        $this->start($brand);
        $payment = $this->pendingPayment('plugin', ['merchant_id' => 'm', 'callback_base_url' => self::CALLBACK_BASE], 'winapay');

        $this->postWinapayCallback($payment);

        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame('/payment/return/'.$payment->getReturnToken(), $this->client->getResponse()->headers->get('Location'));
    }

    public function testACallbackIsProcessedBeforeTheRedirect(): void
    {
        $this->start();
        $payment = $this->pendingPayment(PaymentGatewayType::ZIBAL, ['merchant' => 'm']);
        $payment->setGatewayTransactionId('trk-1')->setAuthority('trk-1');
        $this->em->flush();
        $this->http->onJson('gateway.zibal.ir/v1/verify', ['result' => 100, 'amount' => 1500000]);

        $this->client->request('GET', self::OPERATIONAL_BASE.'/payment/callback/zibal', ['trackId' => 'trk-1', 'success' => '1', 'rt' => $payment->getReturnToken()]);

        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame(self::BRAND_BASE.'/payment/return/'.$payment->getReturnToken(), $this->client->getResponse()->headers->get('Location'));
        self::assertSame(PaymentStatus::CONFIRMED, $this->row('payment', (int) $payment->getId())['status']);
    }

    public function testAnUnknownTokenNeverRedirects(): void
    {
        $this->start();

        $this->client->request('POST', self::OPERATIONAL_BASE.'/payment/callback/plugin/winapay?rt='.str_repeat('x', 40), ['Authority' => 'A', 'PaymentStatus' => 'NOK']);

        self::assertFalse($this->client->getResponse()->isRedirect());
    }

    public function testWinapayCallbackUrlStaysOnTheCallbackBaseWithABrand(): void
    {
        $this->start();
        $payment = $this->pendingPayment('plugin', ['merchant_id' => 'M', 'callback_base_url' => self::CALLBACK_BASE], 'winapay');
        $sent = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent[] = (string) ($options['body'] ?? '');

            return new MockResponse((string) json_encode(['Status' => 100, 'Authority' => 'A0000000000000000000000000000001']));
        });
        $adapter = new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode('winapay'), new WinapayGatewayPlugin($client), false, self::BRAND_BASE);

        $adapter->createPayment($payment, $payment->getOrder());

        self::assertNotEmpty($sent);
        self::assertStringContainsString(rawurlencode(self::OPERATIONAL_BASE.'/payment/callback/plugin/winapay'), implode('', $sent));
        self::assertStringNotContainsString('brand.example.org', implode('', $sent));
    }

    public function testNowPaymentsSendsTheCustomerBackToTheBrandAndItsIpnToTheCallbackBase(): void
    {
        $this->start();
        [$sent, $payment] = $this->createNowPayment();

        self::assertSame(self::BRAND_BASE.'/payment/return/'.$payment->getReturnToken(), $sent['success_url']);
        self::assertSame(self::BRAND_BASE.'/payment/return/'.$payment->getReturnToken().'/cancel', $sent['cancel_url']);
        self::assertSame(self::CALLBACK_BASE.'/payment/webhook/nowpayments', $sent['ipn_callback_url']);
    }

    public function testNowPaymentsWithoutABrandIsAsToday(): void
    {
        $this->start(null);
        [$sent, $payment] = $this->createNowPayment();

        self::assertSame(self::CALLBACK_BASE.'/payment/return/'.$payment->getReturnToken(), $sent['success_url']);
        self::assertSame(self::CALLBACK_BASE.'/payment/return/'.$payment->getReturnToken().'/cancel', $sent['cancel_url']);
        self::assertSame(self::CALLBACK_BASE.'/payment/webhook/nowpayments', $sent['ipn_callback_url']);
    }

    /**
     * @return array{array<string, mixed>, Payment}
     */
    private function createNowPayment(): array
    {
        $payment = $this->pendingPayment(PaymentGatewayType::NOWPAYMENTS, [
            'api_key' => 'k', 'ipn_secret' => 's', 'callback_base_url' => self::CALLBACK_BASE, 'payment_mode' => 'invoice', 'price_currency' => 'usd', 'toman_per_usd' => 50000,
        ]);
        $this->http->onJson('/v1/invoice', ['id' => '5550001', 'invoice_url' => 'https://nowpayments.test/invoice/5550001']);

        self::getContainer()->get(NowPaymentsGateway::class)->createPayment($payment, $payment->getOrder());

        $requests = $this->http->requests('/v1/invoice');
        self::assertCount(1, $requests);

        return [json_decode((string) $requests[0]['options']['body'], true), $payment];
    }

    public function testCustomApiSplitsItsThreeUrls(): void
    {
        $this->start();
        $payment = $this->pendingPayment(PaymentGatewayType::CUSTOM_API, [
            'create' => [
                'url' => 'https://psp.test/create',
                'method' => 'POST',
                'body' => ['callback' => '{{ callback_url }}', 'return' => '{{ return_url }}', 'webhook' => '{{ webhook_url }}'],
                'response_mapping' => ['success' => 'ok', 'payment_url' => 'url', 'transaction_id' => 'id'],
            ],
        ]);
        $this->http->onJson('psp.test/create', ['ok' => true, 'url' => 'https://psp.test/pay/1', 'id' => 'tx-1']);

        self::getContainer()->get(CustomApiGateway::class)->createPayment($payment, $payment->getOrder());

        $sent = json_decode((string) $this->http->requests('psp.test/create')[0]['options']['body'], true);
        $id = $payment->getGateway()?->getId();
        self::assertSame(self::BRAND_BASE.'/payment/return/'.$payment->getReturnToken(), $sent['return']);
        self::assertSame(self::OPERATIONAL_BASE.'/payment/callback/custom-api/'.$id.'?rt='.$payment->getReturnToken(), $sent['callback']);
        self::assertSame(self::OPERATIONAL_BASE.'/payment/webhook/custom-api/'.$id, $sent['webhook']);
    }

    public function testSwapWalletBuildsItsReturnUrlOnTheBrandAndItsWebhookOnTheCallbackBase(): void
    {
        $this->start();
        [$sent, $payment] = $this->createSwapWalletInvoice(self::BRAND_BASE);

        self::assertSame(self::BRAND_BASE.'/payment/return/'.$payment->getReturnToken(), $sent['returnUrl']);
        self::assertStringStartsWith(self::CALLBACK_BASE.'/payment/webhook/plugin/swapwallet', (string) $sent['webhookUrl']);
    }

    public function testSwapWalletWithoutABrandIsAsToday(): void
    {
        $this->start(null);
        [$sent, $payment] = $this->createSwapWalletInvoice(null);

        self::assertSame(self::CALLBACK_BASE.'/payment/return/'.$payment->getReturnToken(), $sent['returnUrl']);
    }

    public function testAStoredConfigCannotChooseTheReturnBase(): void
    {
        $this->start(null);
        [$sent] = $this->createSwapWalletInvoice(null, ['_amoobot_return_base_url' => 'https://evil.example.com']);

        self::assertStringStartsWith(self::CALLBACK_BASE.'/', (string) $sent['returnUrl']);
    }

    public function testTheRegistryHandsPluginsTheBrandBase(): void
    {
        $this->start();

        $brand = (new \ReflectionProperty(PaymentGatewayRegistry::class, 'brand'))->getValue(self::getContainer()->get(PaymentGatewayRegistry::class));

        self::assertSame(self::BRAND_BASE, $brand?->base());
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{array<string, mixed>, Payment}
     */
    private function createSwapWalletInvoice(?string $returnBase, array $config = []): array
    {
        $payment = $this->pendingPayment('plugin', $config + [
            'api_key' => 'k', 'api_base_url' => 'https://swap.test', 'toman_per_usd' => 50000, 'callback_base_url' => self::CALLBACK_BASE,
            'amount_unit' => 'toman', 'payment_mode' => 'invoice', 'price_currency' => 'USD',
        ], 'swapwallet');
        $this->http->onJson('swap.test', ['status' => 'OK', 'result' => ['id' => 'INV1', 'url' => 'https://swap.test/pay/INV1']]);
        $adapter = new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode('swapwallet'), new SwapWalletGatewayPlugin(new MockHttpClient($this->http)), false, $returnBase);

        $adapter->createPayment($payment, $payment->getOrder());

        $requests = $this->http->requests('swap.test');
        self::assertNotEmpty($requests);

        return [json_decode((string) $requests[0]['options']['body'], true), $payment];
    }
}
