<?php

declare(strict_types=1);

namespace Amoobot\Plugin\HooshPay\Tests;

use Amoobot\Plugin\HooshPay\HooshPayApi;
use Amoobot\Plugin\HooshPay\HooshPayGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\User;
use App\Payment\Domain\Dto\PaymentVerificationResult;
use App\Payment\Domain\GatewayPayableAmount;
use App\Payment\Domain\Money\AmountMatcher;
use App\Payment\Domain\Money\AmountMatchStatus;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use App\Entity\Plugin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;

foreach (['HooshPayException', 'HooshPayApi', 'HooshPayGatewayPlugin'] as $class) {
    require_once dirname(__DIR__).'/src/'.$class.'.php';
}

/**
 * HooshPay plugin against a mocked HTTP layer: no network is ever used. The key and the Secret are obviously fake.
 */
final class HooshPayGatewayPluginTest extends TestCase
{
    private const API_KEY = 'hp_live_FAKE-hooshpay-key-0000000000';
    private const SECRET = 'FAKE-hooshpay-secret-1111111111';
    private const UID = 'inv_AbC123xyz';
    private const PAYMENT_ID = 4242;
    private const TRACKING = 'TRK-7';
    private const ORDER_ID = 'TRK-7-P4242';
    private const CARD_NUMBER = '6037991234567890';
    private const HOLDER = 'علی رضایی';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $responses = [];

    private function plugin(): HooshPayGatewayPlugin
    {
        return new HooshPayGatewayPlugin(new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
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
        $this->responses[] = new MockResponse((string) json_encode($json, JSON_UNESCAPED_UNICODE), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * HooshPay's documented create answer, card object included.
     *
     * @param array<string, mixed> $data
     */
    private function respondCreated(array $data = [], int $amount = 250_000): void
    {
        $this->respond(['success' => true, 'data' => array_replace([
            'uid' => self::UID,
            'amount' => $amount,
            'fee_mode' => 'buyer',
            'fee_percent' => 20,
            'fee_amount' => 50_000,
            'payable_amount' => 300_017,
            'merchant_credit' => $amount,
            'status' => 'pending',
            'payment_url' => 'https://hooshpay.xyz/pay/'.self::UID,
            'expires_at' => (new \DateTimeImmutable('+30 minutes', new \DateTimeZone('Asia/Tehran')))->format('Y-m-d\TH:i:s'),
            'card' => ['card_number' => self::CARD_NUMBER, 'holder_name' => self::HOLDER, 'bank_name' => 'ملت'],
        ], $data)], 201);
    }

    /**
     * HooshPay's verify answer for a paid invoice.
     *
     * @param array<string, mixed> $top
     * @param array<string, mixed> $data
     */
    private function respondVerified(array $data = [], array $top = []): void
    {
        $this->respond(array_replace(['success' => true, 'paid' => true, 'status' => 'paid', 'data' => array_replace([
            'uid' => self::UID, 'tracking_code' => '556677', 'order_id' => self::ORDER_ID, 'amount' => 250_000,
            'payable_amount' => 300_017, 'card' => ['card_number' => self::CARD_NUMBER, 'holder_name' => self::HOLDER],
        ], $data)], $top));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_replace([
            'enabled' => true,
            'api_key' => self::API_KEY,
            'api_secret' => self::SECRET,
            'base_url' => 'https://hooshpay.xyz',
            'callback_base_url' => 'https://shop.example/',
            'fee_mode' => 'seller',
            'min_amount' => 1000,
            'description_template' => 'Order {order}',
            'invoice_lifetime_seconds' => 1800,
            PluginPaymentGatewayDriverAdapter::CONFIG_RETURN_BASE_URL => null,
        ], $overrides);
    }

    private function payment(int $amount = 250_000, string $currency = 'IRT'): Payment
    {
        $order = (new Order())->setUser(new User())->setAmount($amount)->setTrackingCode(self::TRACKING);
        $gateway = (new PaymentGateway())->setType('plugin')->setPluginCode(HooshPayGatewayPlugin::CODE)->setCurrency($currency)->setConfig($this->config());
        $payment = (new Payment())->setOrder($order)->setGateway($gateway)->setCurrency($currency)->setAmount($amount)->setPayableAmount($amount);
        (new \ReflectionProperty(Payment::class, 'id'))->setValue($payment, self::PAYMENT_ID);

        return $payment;
    }

    /** A payment as createPayment() left it. */
    private function issued(int $amount = 250_000): Payment
    {
        return $this->payment($amount)
            ->setGatewayTransactionId(self::UID)
            ->setPaymentUrl('https://hooshpay.xyz/pay/'.self::UID)
            ->setExpectation((string) $amount, 'IRT')
            ->setRequestPayload(['success' => true, 'uid' => self::UID, 'order_id' => self::ORDER_ID, 'amount' => $amount, GatewayPayableAmount::KEY => $amount + 17])
            ->setExpiresAt(new \DateTimeImmutable('+30 minutes'));
    }

    /**
     * @return array<string, mixed>
     */
    private function sentJson(int $index = 0): array
    {
        return json_decode((string) $this->requests[$index]['options']['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    /** Neither secret nor the card, in any form: not in the result, not in what the payment stores. */
    private function assertNothingSensitive(mixed ...$values): void
    {
        $text = (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        foreach ([self::API_KEY, self::SECRET, self::CARD_NUMBER, self::HOLDER, 'card_number', 'holder_name'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $text);
            self::assertStringNotContainsString(rawurlencode($sensitive), $text);
        }
    }

    private function match(Payment $payment, PaymentVerificationResult $result): AmountMatchStatus
    {
        return (new AmountMatcher())->matchVerification($payment, $result)->status;
    }

    /**
     * @param array<mixed> $payload
     */
    private function webhookRequest(array $payload, ?string $signature, ?string $rawBody = null): Request
    {
        $request = Request::create('/payment/webhook/hooshpay', 'POST', content: $rawBody ?? (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (null !== $signature) {
            $request->headers->set(HooshPayGatewayPlugin::SIGNATURE_HEADER, $signature);
        }

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function webhookPayload(): array
    {
        return [
            'event' => 'payment.success', 'invoice' => self::UID, 'order_id' => self::ORDER_ID, 'status' => 'paid',
            'amount' => 250_000, 'payable_amount' => 300_017, 'merchant_credit' => 250_000, 'fee_amount' => 50_000,
            'fee_mode' => 'buyer', 'tracking_code' => '556677', 'paid_at' => '2026-06-16T12:05:00',
        ];
    }

    // ---- settings ----

    public function testDisabledByDefaultAndUsableOnlyWithEveryValidSetting(): void
    {
        $plugin = $this->plugin();
        self::assertFalse($plugin->isUsable($this->config(['enabled' => false])));
        self::assertFalse($plugin->isUsable(array_diff_key($this->config(), ['enabled' => true])), 'missing switch = off');
        self::assertFalse($plugin->isUsable($this->config(['enabled' => 'true'])), 'only a real true');
        self::assertTrue($plugin->isUsable($this->config()));
        self::assertTrue($plugin->isUsable($this->config(['base_url' => null, 'fee_mode' => null, 'min_amount' => null, 'invoice_lifetime_seconds' => null])), 'safe defaults');
        self::assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'no api_key' => [['api_key' => ' '], 'api_key_missing'];
        yield 'no api_secret' => [['api_secret' => ''], 'api_secret_missing'];
        yield 'http base_url' => [['base_url' => 'http://hooshpay.xyz'], 'base_url_not_https'];
        yield 'base_url with user info' => [['base_url' => 'https://u:p@hooshpay.xyz'], 'base_url_not_https'];
        yield 'http callback_base_url' => [['callback_base_url' => 'http://shop.example'], 'callback_base_url_not_https'];
        yield 'callback_base_url with a query' => [['callback_base_url' => 'https://shop.example/?x=1'], 'callback_base_url_not_https'];
        yield 'no callback_base_url' => [['callback_base_url' => null], 'callback_base_url_not_https'];
        yield 'unknown fee_mode' => [['fee_mode' => 'nobody'], 'fee_mode_invalid'];
        yield 'min_amount 999' => [['min_amount' => 999], 'min_amount_below_1000'];
        yield 'min_amount not a number' => [['min_amount' => 'abc'], 'min_amount_below_1000'];
        yield 'max below min' => [['min_amount' => 5000, 'max_amount' => 4000], 'max_amount_invalid'];
        yield 'max below 1000' => [['min_amount' => null, 'max_amount' => 500], 'max_amount_invalid'];
        yield 'lifetime 10 s' => [['invoice_lifetime_seconds' => 10], 'invoice_lifetime_seconds_out_of_range'];
        yield 'lifetime two days' => [['invoice_lifetime_seconds' => 172800], 'invoice_lifetime_seconds_out_of_range'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsMakeTheGatewayUnusable(array $overrides, string $problem): void
    {
        $config = $this->config($overrides);
        self::assertContains($problem, HooshPayGatewayPlugin::configProblems($config));
        self::assertFalse($this->plugin()->isUsable($config));

        $result = $this->plugin()->createPayment($this->payment(), $this->payment()->getOrder(), $config);
        self::assertFalse($result->success);
        self::assertSame('not_usable', $result->rawResponse['error'] ?? null);
        self::assertSame([], $this->requests, 'nothing is asked of HooshPay');
    }

    public function testAmountLimitsFollowTheSettingsAndNeverGoBelow1000(): void
    {
        $plugin = $this->plugin();
        self::assertFalse($plugin->acceptsAmount(999), 'HooshPay minimum without a config');
        self::assertTrue($plugin->acceptsAmount(1000));

        self::assertTrue($plugin->isUsable($this->config(['min_amount' => 20_000, 'max_amount' => 500_000])));
        self::assertFalse($plugin->acceptsAmount(19_999));
        self::assertTrue($plugin->acceptsAmount(20_000));
        self::assertTrue($plugin->acceptsAmount(500_000));
        self::assertFalse($plugin->acceptsAmount(500_001));
    }

    /**
     * @return iterable<string, array{int, array<string, mixed>}>
     */
    public static function outOfRange(): iterable
    {
        yield 'below the hard minimum' => [999, []];
        yield 'below min_amount' => [19_999, ['min_amount' => 20_000]];
        yield 'above max_amount' => [500_001, ['max_amount' => 500_000]];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('outOfRange')]
    public function testCreateRefusesAnAmountOutsideTheLimits(int $amount, array $overrides): void
    {
        $payment = $this->payment($amount);
        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config($overrides));

        self::assertFalse($result->success);
        self::assertSame('amount_out_of_range', $result->rawResponse['error'] ?? null);
        self::assertStringContainsString('تومان', (string) $result->message);
        self::assertSame([], $this->requests);
    }

    // ---- create ----

    public function testCreateSendsTomanAndOurReferencesAndStoresNoCard(): void
    {
        $payment = $this->payment(250_000);
        $this->respondCreated(['mini_app_link' => 'https://t.me/SomeBot/app?startapp=x']);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['fee_mode' => 'buyer']));

        self::assertTrue($result->success, (string) $result->message);
        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://hooshpay.xyz/api/v1/invoices', $this->requests[0]['url']);
        self::assertContains('X-API-KEY: '.self::API_KEY, $this->requests[0]['options']['headers']);
        self::assertSame([
            'amount' => 250_000,
            'fee_mode' => 'buyer',
            'description' => 'Order TRK-7',
            'order_id' => self::ORDER_ID,
            'callback_url' => 'https://shop.example/payment/webhook/hooshpay',
            'return_url' => 'https://shop.example/payment/return/'.$payment->getReturnToken(),
        ], $this->sentJson());

        self::assertSame(self::UID, $result->transactionId);
        self::assertSame('https://hooshpay.xyz/pay/'.self::UID, $result->paymentUrl);
        self::assertSame('250000', $payment->getExpectedAmount(), 'the expectation is our amount, never payable_amount');
        self::assertSame('IRT', $payment->getExpectedCurrency());
        self::assertSame(300_017, $result->rawResponse[GatewayPayableAmount::KEY] ?? null, 'payable_amount is kept for display');
        self::assertSame(self::ORDER_ID, $result->rawResponse['order_id'] ?? null);
        self::assertArrayNotHasKey('card', (array) $result->rawResponse);
        self::assertArrayNotHasKey('payment_url', (array) $result->rawResponse);
        self::assertArrayNotHasKey('mini_app_link', (array) $result->rawResponse, 'the Mini App opens payment_url (PaymentTelegramLink finds nothing)');
        $this->assertNothingSensitive($result, $payment->getRequestPayload(), $payment->getVerifyPayload());
    }

    /**
     * Payment::amount and payableAmount are Toman whatever the gateway's display currency says; HooshPay counts in
     * Toman: the amount is sent unchanged, never multiplied by ten.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function units(): iterable
    {
        yield 'IRT gateway' => ['IRT', 150_000];
        yield 'IRR gateway' => ['IRR', 150_000];
        yield 'smallest amount' => ['IRR', 1000];
    }

    #[DataProvider('units')]
    public function testTheAmountIsSentInTomanUnconverted(string $gatewayCurrency, int $toman): void
    {
        $payment = $this->payment($toman, $gatewayCurrency);
        self::assertSame($toman, HooshPayGatewayPlugin::hooshPayAmount($payment));
        $this->respondCreated(['payable_amount' => $toman + 9], $toman);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertTrue($result->success, (string) $result->message);
        self::assertSame($toman, $this->sentJson()['amount']);
        self::assertSame((string) $toman, $payment->getExpectedAmount());
    }

    public function testThePayableAmountIsPreferredOverTheOrderAmount(): void
    {
        $payment = $this->payment(250_000)->setPayableAmount(240_000);
        $this->respondCreated([], 240_000);

        $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertSame(240_000, $this->sentJson()['amount']);
    }

    public function testAUnicodeDescriptionIsSentAsUtf8(): void
    {
        $payment = $this->payment();
        $this->respondCreated();

        $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['description_template' => "خرید اشتراک {order}\nمبلغ {amount} / پرداخت {payment}"]));

        $body = (string) $this->requests[0]['options']['body'];
        self::assertStringContainsString('خرید اشتراک TRK-7 مبلغ 250000 / پرداخت 4242', $body, 'raw UTF-8, no \\u escapes, control characters folded');
        self::assertSame('خرید اشتراک TRK-7 مبلغ 250000 / پرداخت 4242', $this->sentJson()['description']);
    }

    public function testTheReturnUrlUsesTheBrandDomainAndTheCallbackStaysOnTheCallbackBase(): void
    {
        $payment = $this->payment();
        $this->respondCreated();

        $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config([PluginPaymentGatewayDriverAdapter::CONFIG_RETURN_BASE_URL => 'https://brand.example']));

        self::assertSame('https://brand.example/payment/return/'.$payment->getReturnToken(), $this->sentJson()['return_url']);
        self::assertSame('https://shop.example/payment/webhook/hooshpay', $this->sentJson()['callback_url']);
    }

    public function testACustomBaseUrlIsUsed(): void
    {
        $payment = $this->payment();
        $this->respondCreated(['payment_url' => 'https://sandbox.hooshpay.example/pay/'.self::UID]);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['base_url' => 'https://sandbox.hooshpay.example/']));

        self::assertTrue($result->success);
        self::assertSame('https://sandbox.hooshpay.example/api/v1/invoices', $this->requests[0]['url']);
    }

    /**
     * expires_at is built when the test runs (a provider runs when the suite starts, maybe minutes earlier).
     *
     * @return iterable<string, array{string|null, string, string|null}>
     */
    public static function expiries(): iterable
    {
        yield 'no offset: Tehran time' => ['Asia/Tehran', 'Y-m-d\\TH:i:s', '+20 minutes'];
        yield 'UTC with Z' => ['UTC', 'Y-m-d\\TH:i:s\\Z', '+25 minutes'];
        yield 'missing: fallback' => [null, null, '+1800 seconds'];
        yield 'garbage: fallback' => [null, 'tomorrow-ish', '+1800 seconds'];
        yield 'already past: fallback' => [null, '2020-01-01T00:00:00', '+1800 seconds'];
    }

    #[DataProvider('expiries')]
    public function testTheInvoiceExpiryComesFromExpiresAtElseTheFallback(?string $zone, ?string $format, string $expected): void
    {
        $expiresAt = null === $zone ? $format : (new \DateTimeImmutable($expected, new \DateTimeZone($zone)))->format((string) $format);
        $payment = $this->payment();
        $this->respondCreated(['expires_at' => $expiresAt]);

        $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertEqualsWithDelta((new \DateTimeImmutable($expected))->getTimestamp(), $payment->getExpiresAt()?->getTimestamp(), 5);
    }

    public function testTheFallbackLifetimeIsASetting(): void
    {
        $payment = $this->payment();
        $this->respondCreated(['expires_at' => null]);

        $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['invoice_lifetime_seconds' => 600]));

        self::assertEqualsWithDelta(time() + 600, $payment->getExpiresAt()?->getTimestamp(), 5);
    }

    public function testALiveInvoiceIsHandedOutAgainWithoutAnApiCall(): void
    {
        $payment = $this->issued();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertTrue($result->success);
        self::assertSame(self::UID, $result->transactionId);
        self::assertNull($result->rawResponse, 'the stored request payload (order_id, payable amount) stays');
        self::assertSame([], $this->requests);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badCreateAnswers(): iterable
    {
        yield 'another amount' => [['amount' => 249_000], 'amount_mismatch'];
        yield 'payment_url on another host' => [['payment_url' => 'https://evil.example/pay/'.self::UID], 'invalid_link'];
        yield 'payment_url over http' => [['payment_url' => 'http://hooshpay.xyz/pay/'.self::UID], 'invalid_link'];
        yield 'no payable_amount' => [['payable_amount' => null], 'invalid_payable_amount'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('badCreateAnswers')]
    public function testAnUnusableInvoiceIsCancelledAndRefused(array $data, string $error): void
    {
        $payment = $this->payment();
        $this->respondCreated($data);
        $this->respond(['success' => true]);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
        self::assertSame($error, $result->rawResponse['error'] ?? null);
        self::assertSame(HooshPayGatewayPlugin::CUSTOMER_FAILED, $result->message);
        self::assertCount(2, $this->requests);
        self::assertSame('https://hooshpay.xyz/api/v1/invoices/'.self::UID.'/cancel', $this->requests[1]['url']);
        self::assertNull($payment->getExpectedAmount());
    }

    public function testAnInvalidUidIsRefused(): void
    {
        $payment = $this->payment();
        $this->respondCreated(['uid' => '../../x']);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
        self::assertSame('invalid_uid', $result->rawResponse['error'] ?? null);
    }

    /**
     * @return iterable<string, array{MockResponse|\Throwable, string, string}>
     */
    public static function createFailures(): iterable
    {
        yield '503 gateway disabled / no card' => [new MockResponse('<html>busy</html>', ['http_code' => 503]), 'gateway_unavailable', HooshPayGatewayPlugin::CUSTOMER_UNAVAILABLE];
        yield 'timeout' => [new TransportException('Idle timeout reached for "https://hooshpay.xyz/api/v1/invoices".'), 'timeout', HooshPayGatewayPlugin::CUSTOMER_UNAVAILABLE];
        yield 'network' => [new TransportException('Could not resolve host'), 'transport', HooshPayGatewayPlugin::CUSTOMER_UNAVAILABLE];
        yield 'html page' => [new MockResponse('<html>oops</html>', ['http_code' => 502]), 'transport', HooshPayGatewayPlugin::CUSTOMER_UNAVAILABLE];
        yield '401' => [new MockResponse('', ['http_code' => 401]), 'unauthorized', HooshPayGatewayPlugin::CUSTOMER_FAILED];
        yield '403' => [new MockResponse('{"success":false}', ['http_code' => 403]), 'store_disabled', HooshPayGatewayPlugin::CUSTOMER_FAILED];
        yield '400' => [new MockResponse('{"success":false,"message":"amount out of range"}', ['http_code' => 400]), 'http_400', HooshPayGatewayPlugin::CUSTOMER_FAILED];
        yield 'success false' => [new MockResponse('{"success":false}', ['http_code' => 200]), 'success_not_true', HooshPayGatewayPlugin::CUSTOMER_FAILED];
    }

    #[DataProvider('createFailures')]
    public function testCreateFailuresAreFriendlyAndNotRetried(MockResponse|\Throwable $response, string $error, string $message): void
    {
        $payment = $this->payment();
        $this->responses[] = $response;

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
        self::assertSame($message, $result->message);
        self::assertSame($error, $result->rawResponse['error'] ?? null);
        self::assertCount(1, $this->requests, 'never retried');
        $this->assertNothingSensitive($result);
    }

    public function testAProviderMessageQuotingTheSecretsIsMasked(): void
    {
        $payment = $this->payment();
        $this->respond(['success' => false, 'message' => 'bad key '.self::API_KEY.' / '.self::SECRET], 422);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertSame('bad key [redacted] / [redacted]', $result->rawResponse['message'] ?? null);
        $this->assertNothingSensitive($result);
    }

    // ---- webhook signature ----

    public function testAValidSignatureIsAcceptedAndOnlyTheUidIsAReference(): void
    {
        $payload = self::webhookPayload() + ['payment_id' => '1'];
        $result = $this->plugin()->handleWebhook([], $this->webhookRequest($payload, HooshPayGatewayPlugin::signature($payload, self::SECRET)), $this->config());

        self::assertNotNull($result);
        self::assertTrue($result->handled);
        self::assertSame(self::UID, $result->payload['transaction_id'] ?? null);
        self::assertNull($result->payload['payment_id'] ?? null, 'a signed payment_id is still no reference');
        self::assertSame([], $this->requests, 'authentication needs no API call');
    }

    public function testTheSignatureMatchesHooshPaysOwnSample(): void
    {
        // HooshPay's PHP sample, verbatim in substance: ksort, re-encode unescaped, hex HMAC-SHA256 with the Secret.
        $payload = self::webhookPayload();
        $sorted = $payload;
        ksort($sorted);
        $expected = hash_hmac('sha256', (string) json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::SECRET);

        self::assertSame($expected, HooshPayGatewayPlugin::signature($payload, self::SECRET));
    }

    public function testTheSignatureCoversTheDecodedPayloadNotTheRawBytes(): void
    {
        // HooshPay may send keys in any order and with escaped slashes or Unicode: the signature is over the re-encoding.
        $payload = self::webhookPayload() + ['description' => 'خرید / اشتراک'];
        $raw = (string) json_encode(array_reverse($payload, true));
        self::assertStringContainsString('\u', $raw);

        $result = $this->plugin()->handleWebhook([], $this->webhookRequest($payload, strtoupper(HooshPayGatewayPlugin::signature($payload, self::SECRET)), $raw), $this->config());

        self::assertTrue($result?->handled);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSignatures(): iterable
    {
        yield 'missing header' => ['missing'];
        yield 'empty header' => ['empty'];
        yield 'not hex' => ['not_hex'];
        yield 'signed with another secret' => ['other_secret'];
        yield 'tampered amount' => ['tampered_amount'];
        yield 'tampered invoice' => ['tampered_invoice'];
        yield 'added key' => ['added_key'];
        yield 'not json' => ['not_json'];
        yield 'json list' => ['json_list'];
        yield 'no secret configured' => ['no_secret'];
    }

    #[DataProvider('badSignatures')]
    public function testABadSignatureIsUnauthorized(string $case): void
    {
        $payload = self::webhookPayload();
        $good = HooshPayGatewayPlugin::signature($payload, self::SECRET);
        $config = $this->config();
        $raw = null;
        $signature = $good;
        switch ($case) {
            case 'missing': $signature = null; break;
            case 'empty': $signature = ''; break;
            case 'not_hex': $signature = str_repeat('z', 64); break;
            case 'other_secret': $signature = HooshPayGatewayPlugin::signature($payload, 'another-secret'); break;
            case 'tampered_amount': $payload['amount'] = 1000; break;
            case 'tampered_invoice': $payload['invoice'] = 'inv_other'; break;
            case 'added_key': $payload['extra'] = 'x'; break;
            case 'not_json': $raw = 'invoice='.self::UID; break;
            case 'json_list': $raw = '["a"]'; break;
            case 'no_secret': $config['api_secret'] = ''; break;
        }

        $result = $this->plugin()->handleWebhook([], $this->webhookRequest($payload, $signature, $raw), $config);

        self::assertNotNull($result);
        self::assertFalse($result->handled);
        self::assertSame(HooshPayGatewayPlugin::WEBHOOK_UNAUTHORIZED, $result->message);
        self::assertSame([], $this->requests);
    }

    public function testADisabledGatewayRefusesEvenAValidWebhook(): void
    {
        $payload = self::webhookPayload();
        $result = $this->plugin()->handleWebhook([], $this->webhookRequest($payload, HooshPayGatewayPlugin::signature($payload, self::SECRET)), $this->config(['enabled' => false]));

        self::assertFalse($result?->handled);
        self::assertSame(HooshPayGatewayPlugin::WEBHOOK_DISABLED, $result?->message);
    }

    public function testASignedPayloadWithoutAValidInvoiceIsRefused(): void
    {
        $payload = ['event' => 'payment.success', 'invoice' => '../x'];
        $result = $this->plugin()->handleWebhook([], $this->webhookRequest($payload, HooshPayGatewayPlugin::signature($payload, self::SECRET)), $this->config());

        self::assertFalse($result?->handled);
        self::assertSame(HooshPayGatewayPlugin::WEBHOOK_INVALID_PAYLOAD, $result?->message);
    }

    // ---- verify ----

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function feeModes(): iterable
    {
        // payable_amount differs in every mode: unique-amount adjustment, plus the buyer's whole or half fee.
        yield 'seller' => ['seller', 250_013];
        yield 'buyer' => ['buyer', 300_017];
        yield 'split' => ['split', 275_021];
    }

    #[DataProvider('feeModes')]
    public function testAConfirmedPaymentReportsOurAmountNeverThePayableAmount(string $feeMode, int $payable): void
    {
        $payment = $this->issued(250_000);
        $this->respondVerified(['fee_mode' => $feeMode, 'payable_amount' => $payable, 'merchant_credit' => 200_000]);

        $result = $this->plugin()->verifyPayment($payment, ['amount' => 9_999_999, 'invoice' => 'inv_other'], $this->config(['fee_mode' => $feeMode]));

        self::assertTrue($result->success);
        self::assertTrue($result->paid);
        self::assertSame('250000', $result->amount);
        self::assertSame('IRT', $result->currency);
        self::assertSame('556677', $result->refId);
        self::assertSame(AmountMatchStatus::MATCH, $this->match($payment, $result));
        self::assertSame('https://hooshpay.xyz/api/v1/invoices/'.self::UID.'/verify', $this->requests[0]['url'], 'the stored uid, never the payload');
        self::assertSame('POST', $this->requests[0]['method']);
        $this->assertNothingSensitive($result, $payment->getVerifyPayload());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function mismatches(): iterable
    {
        yield 'amount mismatch' => [['amount' => 249_000], [], 'amount_mismatch'];
        yield 'payable amount as amount' => [['amount' => 300_017], [], 'amount_mismatch'];
        yield 'decimal amount' => [['amount' => '250000.0'], [], 'amount_not_integer'];
        yield 'order_id mismatch' => [['order_id' => 'OTHER-P1'], [], 'order_id_mismatch'];
        yield 'uid mismatch' => [['uid' => 'inv_other'], [], 'uid_mismatch'];
        yield 'uid missing' => [['uid' => null], [], 'uid_missing'];
        yield 'status not paid' => [[], ['status' => 'pending'], 'status_not_paid'];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $top
     */
    #[DataProvider('mismatches')]
    public function testAPaidAnswerThatDoesNotMatchIsNotSettled(array $data, array $top, string $reason): void
    {
        $payment = $this->issued();
        $this->respondVerified($data, $top);

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertFalse($result->success, 'nothing decided: no settlement, no review');
        self::assertFalse($result->paid);
        self::assertNull($result->amount);
        self::assertSame(HooshPayGatewayPlugin::ERROR_VERIFY_MISMATCH, $result->rawResponse['error'] ?? null);
        self::assertContains($reason, $result->rawResponse['reasons'] ?? []);
        self::assertSame($reason, $payment->getVerifyPayload()[HooshPayGatewayPlugin::REVIEW_EVIDENCE_KEY]['reason'] ?? null);
        $this->assertNothingSensitive($result, $payment->getVerifyPayload());
    }

    public function testPaidFalseIsNotPaid(): void
    {
        $payment = $this->issued();
        $this->respond(['success' => true, 'paid' => false, 'status' => 'pending', 'data' => ['uid' => self::UID]]);

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertTrue($result->success);
        self::assertFalse($result->paid);
        self::assertFalse($result->failed, 'never reported failed: the core leaves the payment pending');
    }

    public function testMissingOrderIdAndAmountAreReadFromTheInvoiceStatus(): void
    {
        $payment = $this->issued();
        $this->respond(['success' => true, 'paid' => true, 'status' => 'paid', 'data' => ['uid' => self::UID, 'tracking_code' => '556677']]);
        $this->respond(['success' => true, 'data' => ['uid' => self::UID, 'order_id' => self::ORDER_ID, 'amount' => 250_000, 'status' => 'paid']]);

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertTrue($result->paid);
        self::assertSame('250000', $result->amount);
        self::assertSame('GET', $this->requests[1]['method']);
        self::assertSame('https://hooshpay.xyz/api/v1/invoices/'.self::UID, $this->requests[1]['url']);
    }

    public function testWithoutOrderIdAndAmountAnywhereNothingIsSettled(): void
    {
        $payment = $this->issued();
        $this->respond(['success' => true, 'paid' => true, 'status' => 'paid', 'data' => ['uid' => self::UID]]);
        $this->respond(['success' => true, 'data' => ['uid' => 'inv_other', 'order_id' => self::ORDER_ID, 'amount' => 250_000]]);

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertFalse($result->paid);
        self::assertSame(['order_id_missing', 'amount_missing'], $result->rawResponse['reasons'] ?? null, 'another invoice\'s status is not used');
    }

    /**
     * @return iterable<string, array{MockResponse|\Throwable, string|null}>
     */
    public static function verifyFailures(): iterable
    {
        yield '503' => [new MockResponse('', ['http_code' => 503]), 'gateway_unavailable'];
        yield 'timeout' => [new TransportException('Idle timeout reached'), 'timeout'];
        yield '401' => [new MockResponse('', ['http_code' => 401]), 'unauthorized'];
        yield '404' => [new MockResponse('{"success":false}', ['http_code' => 404]), null];
        yield '500 json' => [new MockResponse('{"success":false}', ['http_code' => 500]), null];
        yield 'success false' => [new MockResponse('{"success":false,"paid":true}', ['http_code' => 200]), null];
        yield 'unknown status' => [new MockResponse('{"success":true,"paid":false,"status":"weird"}', ['http_code' => 200]), null];
    }

    #[DataProvider('verifyFailures')]
    public function testVerifyFailuresDecideNothing(MockResponse|\Throwable $response, ?string $error): void
    {
        $payment = $this->issued();
        $this->responses[] = $response;

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertFalse($result->success);
        self::assertFalse($result->paid);
        if (null !== $error) {
            self::assertSame($error, $result->rawResponse['error'] ?? null);
        }
        self::assertCount(1, $this->requests);
    }

    public function testVerifyNeedsAStoredUidAndStillWorksWithTheSwitchOff(): void
    {
        $result = $this->plugin()->verifyPayment($this->payment(), ['invoice' => self::UID], $this->config());
        self::assertFalse($result->success);
        self::assertSame([], $this->requests);

        $payment = $this->issued();
        $this->respondVerified();
        self::assertTrue($this->plugin()->verifyPayment($payment, [], $this->config(['enabled' => false]))->paid, 'an invoice issued before the switch went off can still be paid');
    }

    // ---- cancel ----

    public function testCancelInvoicePostsCancelForTheStoredUid(): void
    {
        $this->respond(['success' => true]);
        self::assertSame(HooshPayGatewayPlugin::CANCELLED, $this->plugin()->cancelInvoice($this->issued(), $this->config()));
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://hooshpay.xyz/api/v1/invoices/'.self::UID.'/cancel', $this->requests[0]['url']);
    }

    /**
     * @return iterable<string, array{MockResponse|\Throwable, string}>
     */
    public static function cancelFailures(): iterable
    {
        yield 'not pending (400)' => [new MockResponse('{"success":false}', ['http_code' => 400]), 'http_400'];
        yield '503' => [new MockResponse('', ['http_code' => 503]), 'http_503'];
        yield 'timeout' => [new TransportException('Idle timeout reached'), 'timeout'];
    }

    #[DataProvider('cancelFailures')]
    public function testCancelFailuresAreReportedNotThrown(MockResponse|\Throwable $response, string $outcome): void
    {
        $this->responses[] = $response;
        self::assertSame($outcome, $this->plugin()->cancelInvoice($this->issued(), $this->config()));
    }

    public function testCancelSkipsARowWithoutAHooshPayInvoice(): void
    {
        self::assertSame(HooshPayGatewayPlugin::CANCEL_SKIPPED, $this->plugin()->cancelInvoice($this->payment(), $this->config()));
        $other = $this->issued();
        $other->getGateway()?->setPluginCode('winapay');
        self::assertSame(HooshPayGatewayPlugin::CANCEL_SKIPPED, $this->plugin()->cancelInvoice($other, $this->config()));
        self::assertSame([], $this->requests);
    }

    // ---- package ----

    public function testTheAdapterExposesTheConstraintsAndTheCancellation(): void
    {
        $plugin = (new Plugin())->setCode(HooshPayGatewayPlugin::CODE);
        $adapter = new PluginPaymentGatewayDriverAdapter($plugin, $this->plugin());
        self::assertSame(HooshPayGatewayPlugin::DEFAULT_LIFETIME_SECONDS, $adapter->invoiceLifetimeSeconds());
        self::assertFalse($adapter->isUsable($this->config(['enabled' => false])));
        self::assertTrue($adapter->supportsWebhook());
        self::assertSame(HooshPayGatewayPlugin::CANCEL_SKIPPED, $adapter->cancelInvoice($this->payment()));
    }

    public function testManifestMatchesThePluginClass(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(HooshPayGatewayPlugin::CODE, $manifest['code']);
        self::assertSame(HooshPayGatewayPlugin::class, $manifest['mainClass']);
        self::assertTrue($manifest['supportsWebhook']);
        $fields = array_column($manifest['configSchema'], null, 'key');
        self::assertSame(['enabled', 'api_key', 'api_secret', 'base_url', 'callback_base_url', 'fee_mode', 'min_amount', 'max_amount', 'description_template', 'invoice_lifetime_seconds'], array_keys($fields));
        self::assertFalse($fields['enabled']['default'], 'installed disabled');
        self::assertSame('password', $fields['api_key']['type']);
        self::assertSame('password', $fields['api_secret']['type']);
        self::assertArrayNotHasKey('default', $fields['api_key'], 'no fake secret default');
        self::assertArrayNotHasKey('default', $fields['api_secret']);
        self::assertSame(HooshPayApi::DEFAULT_BASE_URL, $fields['base_url']['default']);
        self::assertSame(HooshPayGatewayPlugin::DEFAULT_FEE_MODE, $fields['fee_mode']['default']);
        self::assertSame(HooshPayGatewayPlugin::FEE_MODES, array_values($fields['fee_mode']['choices']));
        self::assertSame(HooshPayGatewayPlugin::HARD_MIN_AMOUNT_TOMAN, $fields['min_amount']['default']);
        self::assertSame([], HooshPayGatewayPlugin::configProblems(array_replace(array_map(static fn (array $f): mixed => $f['default'] ?? null, $fields), ['api_key' => 'k', 'api_secret' => 's', 'callback_base_url' => 'https://shop.example'])), 'the defaults are valid');
    }
}
