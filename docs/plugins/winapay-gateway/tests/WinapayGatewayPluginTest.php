<?php

declare(strict_types=1);

namespace Amoobot\Plugin\Winapay\Tests;

use Amoobot\Plugin\Winapay\WinapayApi;
use Amoobot\Plugin\Winapay\WinapayGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Entity\User;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\Money\AmountMatcher;
use App\Payment\Domain\Money\AmountMatchStatus;
use App\Payment\Domain\Webhook\NotificationAwareGatewayInterface;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

foreach (['WinapayException', 'WinapayStatus', 'WinapayApi', 'WinapayGatewayPlugin'] as $class) {
    require_once dirname(__DIR__).'/src/'.$class.'.php';
}

/**
 * Winapay plugin against a mocked HTTP layer: no network is ever used.
 */
final class WinapayGatewayPluginTest extends TestCase
{
    private const MERCHANT = 'MERCHANT-SECRET-0123456789abcdef';
    private const AUTHORITY = 'A0000000000000000000000000000001';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $responses = [];

    private function plugin(): WinapayGatewayPlugin
    {
        return new WinapayGatewayPlugin(new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $response = array_shift($this->responses) ?? throw new \LogicException('Unexpected HTTP call to '.$url);
            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        }));
    }

    /**
     * @param array<mixed> $json
     */
    private function respond(array $json, int $status = 200): void
    {
        $this->responses[] = new MockResponse((string) json_encode($json), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_replace([
            'merchant_id' => self::MERCHANT,
            'callback_base_url' => 'https://shop.example/',
            'request_format' => 'form',
            'description_prefix' => 'Shop & Co / سفارش',
            'underpayment_tolerance_percent' => 0,
        ], $overrides);
    }

    private function payment(int $amount = 1000, ?string $mobile = '+98 912 123 4567'): Payment
    {
        $order = (new Order())->setUser((new User())->setMobile($mobile))->setAmount($amount)->setTrackingCode('TRK-42');

        return (new Payment())->setOrder($order)->setAmount($amount)->setPayableAmount($amount)->setTrackingCode('TRK-42');
    }

    private function storedPayment(int $amount = 1000): Payment
    {
        return $this->payment($amount)->setAuthority(self::AUTHORITY)->setGatewayTransactionId(self::AUTHORITY);
    }

    /**
     * @return array<string, string>
     */
    private function sentForm(int $index = 0): array
    {
        parse_str((string) $this->requests[$index]['options']['body'], $form);

        return $form;
    }

    private function assertNoSecret(mixed $value): void
    {
        self::assertStringNotContainsString(self::MERCHANT, (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ---- createPayment ------------------------------------------------------------------------

    public function testCreateSendsTomanAsEncodedFormAndStoresAuthority(): void
    {
        $this->respond(['Status' => 100, 'Authority' => self::AUTHORITY, 'PaymentUrl' => 'https://winapay.io/startPay/'.self::AUTHORITY]);
        $payment = $this->payment(1000);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertTrue($result->success);
        self::assertSame(self::AUTHORITY, $result->transactionId);
        self::assertSame(self::AUTHORITY, $result->authority);
        self::assertSame('https://winapay.io/startPay/'.self::AUTHORITY, $result->paymentUrl);

        $request = $this->requests[0];
        self::assertSame(['POST', 'https://winapay.io/webservice/rest/PaymentRequest'], [$request['method'], $request['url']]);
        self::assertContains('Accept: application/json', $request['options']['headers']);
        self::assertContains('Content-Type: application/x-www-form-urlencoded', $request['options']['headers']);
        self::assertStringContainsString('Description=Shop+%26+Co+%2F+', (string) $request['options']['body'], 'every value is URL-encoded');

        $form = $this->sentForm();
        self::assertSame('1000', $form['Amount'], 'Toman as is, no x10');
        self::assertSame(self::MERCHANT, $form['MerchantID']);
        self::assertSame('Shop & Co / سفارش TRK-42', $form['Description']);
        self::assertSame('TRK-42', $form['InvoiceID']);
        self::assertSame('09121234567', $form['Mobile']);
        self::assertSame('https://shop.example/payment/callback/plugin/winapay?rt='.$payment->getReturnToken(), $form['CallbackURL']);
        self::assertArrayNotHasKey('AllowCard', $form);
        self::assertArrayNotHasKey('Email', $form);
        $this->assertNoSecret($result);
    }

    public function testCreateKeepsTlsVerificationAndRefusesRedirects(): void
    {
        $this->respond(['Status' => 100, 'Authority' => self::AUTHORITY]);
        $payment = $this->payment();

        $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        $options = $this->requests[0]['options'];
        self::assertTrue($options['verify_peer']);
        self::assertTrue($options['verify_host']);
        self::assertSame(0, $options['max_redirects']);
        self::assertSame(WinapayApi::IDLE_TIMEOUT, $options['timeout']);
        self::assertSame(WinapayApi::MAX_DURATION, $options['max_duration']);
    }

    public function testCreateCanSendJson(): void
    {
        $this->respond(['status' => '100', 'authority' => self::AUTHORITY]);
        $payment = $this->payment(2500);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['request_format' => 'json']));

        self::assertTrue($result->success);
        self::assertContains('Content-Type: application/json', $this->requests[0]['options']['headers']);
        $sent = json_decode((string) $this->requests[0]['options']['body'], true);
        self::assertSame(2500, $sent['Amount']);
        self::assertSame('09121234567', $sent['Mobile']);
    }

    public function testCreateBuildsStartPayUrlWhenPaymentUrlIsNotOnWinapay(): void
    {
        $this->respond(['Status' => 100, 'Authority' => self::AUTHORITY, 'PaymentUrl' => 'https://evil.example/startPay/'.self::AUTHORITY]);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertSame('https://winapay.io/startPay/'.self::AUTHORITY, $result->paymentUrl);
    }

    public function testCreateRefusesAmountBelowMinimumWithoutCallingWinapay(): void
    {
        $payment = $this->payment(99);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
        self::assertStringContainsString('100 Toman', (string) $result->message);
        self::assertSame([], $this->requests);
    }

    public function testCreateDoesNotSendAnInvalidMobile(): void
    {
        $this->respond(['Status' => 100, 'Authority' => self::AUTHORITY]);
        $payment = $this->payment(1000, '12345');

        $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertArrayNotHasKey('Mobile', $this->sentForm());
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testCreateRequiresConfig(array $config, string $message): void
    {
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config($config));

        self::assertFalse($result->success);
        self::assertStringContainsString($message, (string) $result->message);
        self::assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'no merchant' => [['merchant_id' => ''], 'merchant_id'];
        yield 'relative callback' => [['callback_base_url' => '/shop'], 'callback_base_url'];
        yield 'non-http callback' => [['callback_base_url' => 'ftp://shop.example'], 'callback_base_url'];
    }

    #[DataProvider('createErrorCodes')]
    public function testCreateFailureForEachErrorGroup(int|string $status, string $message): void
    {
        $this->respond(['Status' => $status, 'MerchantID' => self::MERCHANT, 'Message' => 'bad merchant '.self::MERCHANT]);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
        self::assertStringContainsString($message, (string) $result->message);
        self::assertNull($result->authority);
        $this->assertNoSecret($result);
    }

    /**
     * @return iterable<string, array{int|string, string}>
     */
    public static function createErrorCodes(): iterable
    {
        yield 'credentials -2' => [-2, 'MerchantID is missing'];
        yield 'credentials -6' => [-6, 'MerchantID was not found'];
        yield 'account -7' => [-7, 'inactive'];
        yield 'account -8' => [-8, 'business account'];
        yield 'whitelist -9 (IP)' => [-9, 'IP address is not allowed'];
        yield 'whitelist -10 (domain)' => ['-10', 'callback address does not match'];
        yield 'request -1' => [-1, 'web service'];
        yield 'request -3' => [-3, 'Amount is missing'];
        yield 'request -4' => [-4, 'CallbackURL is missing'];
        yield 'amount -5' => [-5, 'below the 100 Toman minimum'];
        yield 'provider -11' => [-11, 'could not create the transaction'];
        yield 'card -20' => [-20, 'Invalid card number'];
        yield 'transient 102' => [102, 'try again later'];
        yield 'unknown' => [-99, 'Unknown status. (code -99)'];
    }

    public function testCreateFailsWithoutValidAuthority(): void
    {
        $this->respond(['Status' => 100, 'Authority' => '../../x']);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
        self::assertStringContainsString('Authority', (string) $result->message);
    }

    public function testCreateHandlesHtmlResponse(): void
    {
        $this->responses[] = new MockResponse('<html><body>502 Bad Gateway</body></html>', ['http_code' => 502]);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
        self::assertStringContainsString('HTML page instead of JSON (HTTP 502)', (string) $result->message);
    }

    // ---- verifyPayment ------------------------------------------------------------------------

    public function testVerifyReportsProviderAmountInTomanAndUsesStoredAuthority(): void
    {
        $this->respond(['Status' => 100, 'RefID' => 'REF-1', 'Amount' => 1000, 'BuyerIP' => '1.2.3.4', 'PaymentTime' => '2026-10-29 10:00', 'MaskCardNumber' => '603799******1234']);
        $payment = $this->storedPayment(1000);

        $result = $this->plugin()->verifyPayment($payment, ['Authority' => 'FORGED00000000000000000000000000', 'PaymentStatus' => 'OK', 'Amount' => '999999'], $this->config());

        self::assertTrue($result->success);
        self::assertTrue($result->paid);
        self::assertSame(['1000', 'IRT'], [$result->amount, $result->currency]);
        self::assertSame('REF-1', $result->refId);
        self::assertSame(self::AUTHORITY, $result->transactionId);
        self::assertSame(['MerchantID' => self::MERCHANT, 'Authority' => self::AUTHORITY, 'Amount' => '1000'], $this->sentForm(), 'stored Authority and our expected amount');
        self::assertSame('https://winapay.io/webservice/rest/PaymentVerification', $this->requests[0]['url']);
        self::assertSame(AmountMatchStatus::MATCH, $this->match($payment, $result));
        $this->assertNoSecret($result);
    }

    public function testVerifyReportsAMismatchingAmountAsIs(): void
    {
        $this->respond(['Status' => '100', 'RefID' => 'REF-2', 'Amount' => '900']);
        $payment = $this->storedPayment(1000);

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertTrue($result->paid);
        self::assertSame(['900', 'IRT'], [$result->amount, $result->currency]);
        self::assertSame(AmountMatchStatus::UNDERPAID, $this->match($payment, $result), 'core sends it to review');
    }

    /**
     * @param array<string, mixed> $response
     */
    #[DataProvider('incompleteProofs')]
    public function testVerifyWithoutValidAmountOrRefIdGoesToReview(array $response): void
    {
        $this->respond($response);
        $payment = $this->storedPayment(1000);

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertTrue($result->paid);
        self::assertNull($result->amount, 'never filled from our own record');
        self::assertNull($result->currency);
        self::assertSame(AmountMatchStatus::MISSING, $this->match($payment, $result));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function incompleteProofs(): iterable
    {
        yield 'no Amount' => [['Status' => 100, 'RefID' => 'REF-3']];
        yield 'non-numeric Amount' => [['Status' => 100, 'RefID' => 'REF-3', 'Amount' => '1,000']];
        yield 'negative Amount' => [['Status' => 100, 'RefID' => 'REF-3', 'Amount' => -1000]];
        yield 'array Amount' => [['Status' => 100, 'RefID' => 'REF-3', 'Amount' => ['value' => 1000]]];
        yield 'no RefID' => [['Status' => 100, 'Amount' => 1000]];
    }

    public function testAlreadyVerifiedIsNotAFreshPayment(): void
    {
        $this->respond(['Status' => 101, 'RefID' => 'REF-4', 'Amount' => 1000]);
        $payment = $this->storedPayment(1000);

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertTrue($result->success);
        self::assertTrue($result->paid);
        self::assertNull($result->amount, '101 never reports an amount');
        self::assertSame('REF-4', $result->refId);
        self::assertSame(AmountMatchStatus::MISSING, $this->match($payment, $result), 'core sends it to review');
    }

    public function testVerifyTwiceIsSafe(): void
    {
        $this->respond(['Status' => 100, 'RefID' => 'REF-5', 'Amount' => 1000]);
        $this->respond(['Status' => 101, 'RefID' => 'REF-5']);
        $payment = $this->storedPayment(1000);
        $plugin = $this->plugin();

        $first = $plugin->verifyPayment($payment, [], $this->config());
        $second = $plugin->verifyPayment($payment, [], $this->config());

        self::assertSame(AmountMatchStatus::MATCH, $this->match($payment, $first));
        self::assertSame(AmountMatchStatus::MISSING, $this->match($payment, $second), 'a repeat never auto-confirms a second time');
        self::assertSame(self::AUTHORITY, $this->sentForm(1)['Authority']);
    }

    #[DataProvider('notPaidCodes')]
    public function testDefinitiveNotPaidAnswers(int $status, string $message): void
    {
        $this->respond(['Status' => $status]);

        $result = $this->plugin()->verifyPayment($this->storedPayment(), [], $this->config());

        self::assertTrue($result->success, 'Winapay answered');
        self::assertFalse($result->paid);
        self::assertFalse($result->failed, 'an unsigned flow never rejects the payment');
        self::assertStringContainsString($message, (string) $result->message);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function notPaidCodes(): iterable
    {
        yield '-15 not paid' => [-15, 'not completed'];
        yield '-16 amount mismatch' => [-16, 'amount does not match'];
        yield '-12 invalid authority' => [-12, 'Invalid Authority'];
        yield '-14 not found' => [-14, 'not found'];
        yield '-17 foreign authority' => [-17, 'not registered for this merchant'];
        yield '-21 card differs' => [-21, 'allowed card'];
    }

    #[DataProvider('retryableCodes')]
    public function testProviderAndConfigErrorsAreRetryable(int|string|null $status): void
    {
        $this->respond(null === $status ? ['Message' => 'oops'] : ['Status' => $status]);

        $result = $this->plugin()->verifyPayment($this->storedPayment(), [], $this->config());

        self::assertFalse($result->success);
        self::assertFalse($result->paid);
    }

    /**
     * @return iterable<string, array{int|string|null}>
     */
    public static function retryableCodes(): iterable
    {
        yield '102' => [102];
        yield '-9 IP' => [-9];
        yield '-10 domain' => [-10];
        yield '-6 merchant' => [-6];
        yield '-18 wallet' => [-18];
        yield '-19 unknown' => [-19];
        yield 'unknown code' => [-77];
        yield 'garbage status' => ['ok'];
        yield 'no status' => [null];
    }

    #[DataProvider('brokenResponses')]
    public function testMalformedResponsesAreTransportErrors(MockResponse $response, string $message): void
    {
        $this->responses[] = $response;

        $result = $this->plugin()->verifyPayment($this->storedPayment(), [], $this->config());

        self::assertFalse($result->success);
        self::assertFalse($result->paid);
        self::assertStringContainsString($message, (string) $result->message);
    }

    /**
     * @return iterable<string, array{MockResponse, string}>
     */
    public static function brokenResponses(): iterable
    {
        yield 'HTML' => [new MockResponse('<!DOCTYPE html><html>Maintenance</html>', ['http_code' => 503]), 'HTML page'];
        yield 'plain text' => [new MockResponse('Status=100&RefID=1'), 'invalid response'];
        yield 'JSON list' => [new MockResponse('[100]'), 'invalid response'];
        yield 'empty' => [new MockResponse(''), 'empty response'];
        yield 'redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://other.example/']]), 'empty response (HTTP 302)'];
        yield 'oversized' => [new MockResponse('{"Status":100,"x":"'.str_repeat('a', WinapayApi::MAX_RESPONSE_BYTES).'"}'), 'too large'];
        yield 'timeout' => [new MockResponse(['']), 'did not respond in time'];
    }

    public function testTransportErrorIsRetryableAndMasked(): void
    {
        $this->responses[] = new TransportException('SSL certificate problem for '.self::MERCHANT);

        $result = $this->plugin()->verifyPayment($this->storedPayment(), [], $this->config());

        self::assertFalse($result->success);
        self::assertStringContainsString('Could not reach Winapay', (string) $result->message);
        $this->assertNoSecret($result);
    }

    public function testNokCallbackDoesNotCallWinapay(): void
    {
        $result = $this->plugin()->verifyPayment($this->storedPayment(), ['Authority' => self::AUTHORITY, 'PaymentStatus' => 'NOK'], $this->config());

        self::assertTrue($result->success);
        self::assertFalse($result->paid);
        self::assertFalse($result->failed);
        self::assertSame([], $this->requests);
    }

    public function testOkCallbackAloneNeverMeansPaid(): void
    {
        $this->respond(['Status' => -15]);

        $result = $this->plugin()->verifyPayment($this->storedPayment(), ['Authority' => self::AUTHORITY, 'PaymentStatus' => 'OK', 'RefID' => 'X', 'Amount' => 1000], $this->config());

        self::assertFalse($result->paid);
    }

    public function testVerifyWithoutStoredAuthorityIgnoresThePayload(): void
    {
        $result = $this->plugin()->verifyPayment($this->payment(), ['Authority' => self::AUTHORITY], $this->config());

        self::assertFalse($result->success);
        self::assertSame([], $this->requests);
    }

    public function testSecretsNeverReachRawResponse(): void
    {
        $this->respond(['Status' => 100, 'RefID' => 'R', 'Amount' => 1000, 'MerchantID' => self::MERCHANT, 'Echo' => ['merchant_id' => 'x', 'note' => 'id='.self::MERCHANT]]);

        $result = $this->plugin()->verifyPayment($this->storedPayment(), [], $this->config());

        self::assertTrue($result->paid);
        self::assertSame('[redacted]', $result->rawResponse['MerchantID'] ?? null);
        $this->assertNoSecret($result);
    }

    // ---- through the core adapter ----------------------------------------------------------------

    public function testBrowserCallbackIsMatchedByAuthorityAndNokSkipsVerification(): void
    {
        $payment = $this->storedPayment();
        $gateway = (new PaymentGateway())->setType('plugin')->setPluginCode('winapay')->setConfig($this->config());
        $payment->setGateway($gateway);
        $adapter = new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode('winapay'), $this->plugin());
        $request = Request::create('/payment/callback/plugin/winapay?rt='.$payment->getReturnToken(), 'POST', ['Authority' => self::AUTHORITY, 'PaymentStatus' => 'NOK', 'InvoiceID' => 'TRK-42']);

        $notification = $adapter->handleNotification($gateway, $request, NotificationAwareGatewayInterface::CHANNEL_CALLBACK);
        $result = $adapter->verifyPayment($payment, $notification->payload);

        self::assertTrue($notification->authenticated);
        self::assertSame([self::AUTHORITY], $notification->references);
        self::assertFalse($result->paid);
        self::assertSame([], $this->requests);
    }

    public function testWebhooksAreNotSupported(): void
    {
        $gateway = (new PaymentGateway())->setType('plugin')->setPluginCode('winapay')->setConfig($this->config());
        $adapter = new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode('winapay'), $this->plugin());
        $request = Request::create('/payment/webhook/plugin/winapay', 'POST', ['Authority' => self::AUTHORITY, 'PaymentStatus' => 'OK']);

        $notification = $adapter->handleNotification($gateway, $request, NotificationAwareGatewayInterface::CHANNEL_WEBHOOK);

        self::assertFalse($this->plugin()->supportsWebhook());
        self::assertFalse($notification->authenticated);
    }

    public function testManifestMatchesThePluginClass(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/plugin.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $manifest['manifestVersion']);
        self::assertSame('winapay', $manifest['code']);
        self::assertSame(WinapayGatewayPlugin::class, $manifest['mainClass']);
        self::assertSame((new WinapayGatewayPlugin())->getType(), $manifest['code']);
        self::assertFalse($manifest['supportsWebhook']);
        $keys = array_column($manifest['configSchema'], 'key');
        self::assertSame(['merchant_id', 'callback_base_url', 'request_format', 'description_prefix', 'underpayment_tolerance_percent'], $keys);
        self::assertArrayNotHasKey('default', $manifest['configSchema'][0], 'no fake secret default');
    }

    private function match(Payment $payment, PaymentVerificationResult $result): AmountMatchStatus
    {
        return (new AmountMatcher())->matchVerification($payment, $result)->status;
    }
}
