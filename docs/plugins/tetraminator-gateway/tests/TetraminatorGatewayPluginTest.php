<?php

declare(strict_types=1);

namespace Amoobot\Plugin\Tetraminator\Tests;

use Amoobot\Plugin\Tetraminator\TetraminatorApi;
use Amoobot\Plugin\Tetraminator\TetraminatorGatewayPlugin;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Entity\TelegramAccount;
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

foreach (['TetraminatorException', 'TetraminatorApi', 'TetraminatorGatewayPlugin'] as $class) {
    require_once dirname(__DIR__).'/src/'.$class.'.php';
}

/**
 * Tetraminator plugin against a mocked HTTP layer: no network is ever used. The key is obviously fake.
 * docs/plugins/tetraminator-gateway/design.md, decision 1 and section 14.
 */
final class TetraminatorGatewayPluginTest extends TestCase
{
    private const API_KEY = 'FAKE-tetraminator-key-for-tests-0000';
    private const PAY_ID = 'PAY_7f3a-0001';
    private const PAYMENT_ID = 4242;
    private const TELEGRAM_ID = '512345678';
    private const LINK = 'https://pay.tetraminator.example/invoice/PAY_7f3a-0001';
    private const GATEWAY_LINK = 'https://gw.tetraminator.example/g/PAY_7f3a-0001';
    private const MINI_APP_LINK = 'https://t.me/TetraminatorBot/app?startapp=PAY_7f3a-0001';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $responses = [];

    private function plugin(): TetraminatorGatewayPlugin
    {
        return new TetraminatorGatewayPlugin(new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
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
        $this->responses[] = new MockResponse((string) json_encode($json, JSON_PRESERVE_ZERO_FRACTION), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * @param array<mixed> $overrides
     */
    private function respondCreated(array $overrides = [], int $status = 201): void
    {
        $this->respond(array_replace([
            'status' => true,
            'message' => 'Invoice created',
            'pay_id' => self::PAY_ID,
            'url' => self::LINK,
            'payment_link' => 'https://pay.tetraminator.example/p/'.self::PAY_ID,
            'mini_app_link' => 'https://t.me/TetraminatorBot/app?startapp='.self::PAY_ID,
        ], $overrides), $status);
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
            'callback_base_url' => 'https://shop.example/',
            'send_chat_id' => false,
        ], $overrides);
    }

    private function payment(int $amount = 50_000): Payment
    {
        $user = new User();
        $user->setTelegramAccount((new TelegramAccount())->setTelegramId(self::TELEGRAM_ID)->setUser($user));
        $order = (new Order())->setUser($user)->setAmount($amount)->setTrackingCode('TRK-7');
        $gateway = (new PaymentGateway())->setType('plugin')->setPluginCode(TetraminatorGatewayPlugin::CODE)->setConfig($this->config());
        $payment = (new Payment())->setOrder($order)->setGateway($gateway)->setAmount($amount)->setPayableAmount($amount);
        (new \ReflectionProperty(Payment::class, 'id'))->setValue($payment, self::PAYMENT_ID);

        return $payment;
    }

    /** A payment as createPayment() left it: the invoice's pay_id, link, expectation and expiry. */
    private function issued(int $amount = 50_000, string $expiresIn = '+30 minutes'): Payment
    {
        return $this->payment($amount)
            ->setGatewayTransactionId(self::PAY_ID)
            ->setAuthority(hash('sha256', 'token'))
            ->setPaymentUrl(self::LINK)
            ->setExpectation((string) $amount, 'IRT')
            ->setExpiresAt(new \DateTimeImmutable($expiresIn));
    }

    /**
     * @return array<string, mixed>
     */
    private function sentJson(int $index = 0): array
    {
        return json_decode((string) $this->requests[$index]['options']['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    /** The key in no form: not in the result, not in what the payment stores. */
    private function assertNoSecret(mixed ...$values): void
    {
        $text = (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        self::assertStringNotContainsString(self::API_KEY, $text);
        self::assertStringNotContainsString(rawurlencode(self::API_KEY), $text);
    }

    private function match(Payment $payment, PaymentVerificationResult $result): AmountMatchStatus
    {
        return (new AmountMatcher())->matchVerification($payment, $result)->status;
    }

    // ---- constraints ----------------------------------------------------------------------------

    public function testConstraints(): void
    {
        $plugin = $this->plugin();

        self::assertTrue($plugin->isUsable($this->config()));
        self::assertSame(3600, $plugin->invoiceLifetimeSeconds());
        self::assertFalse($plugin->acceptsAmount(49_999));
        self::assertTrue($plugin->acceptsAmount(50_000));
        self::assertTrue($plugin->acceptsAmount(10_000_000));
        self::assertFalse($plugin->acceptsAmount(10_000_001));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('unusableConfigs')]
    public function testIsUsableNeedsEveryField(array $overrides): void
    {
        $config = $this->config();
        foreach ($overrides as $key => $value) {
            if (null === $value) {
                unset($config[$key]);
            } else {
                $config[$key] = $value;
            }
        }

        self::assertFalse($this->plugin()->isUsable($config));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusableConfigs(): iterable
    {
        yield 'enabled missing' => [['enabled' => null]];
        yield 'enabled off' => [['enabled' => false]];
        yield 'enabled as text' => [['enabled' => 'true']];
        yield 'enabled as 1' => [['enabled' => 1]];
        yield 'api_key missing' => [['api_key' => null]];
        yield 'api_key empty' => [['api_key' => '  ']];
        yield 'callback missing' => [['callback_base_url' => null]];
        yield 'callback empty' => [['callback_base_url' => '']];
        yield 'callback http' => [['callback_base_url' => 'http://shop.example']];
        yield 'callback no host' => [['callback_base_url' => 'https:///shop']];
        yield 'callback relative' => [['callback_base_url' => '/shop']];
        yield 'callback user info' => [['callback_base_url' => 'https://admin:pw@shop.example']];
        yield 'callback query' => [['callback_base_url' => 'https://shop.example/?x=1']];
    }

    // ---- createPayment ------------------------------------------------------------------------

    public function testCreateSendsTomanAndStoresTheInvoice(): void
    {
        $this->respondCreated();
        $payment = $this->payment(50_000);
        $before = new \DateTimeImmutable();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertTrue($result->success);
        self::assertSame(self::PAY_ID, $result->transactionId);
        self::assertSame(self::LINK, $result->paymentUrl, 'url first');
        self::assertSame(['50000', 'IRT'], [$payment->getExpectedAmount(), $payment->getExpectedCurrency()]);
        $expiresAt = $payment->getExpiresAt();
        self::assertNotNull($expiresAt);
        self::assertGreaterThanOrEqual($before->modify('+3600 seconds')->getTimestamp(), $expiresAt->getTimestamp());
        self::assertLessThanOrEqual((new \DateTimeImmutable('+3600 seconds'))->getTimestamp(), $expiresAt->getTimestamp());

        $request = $this->requests[0];
        self::assertSame(['POST', 'https://api.tetraminator.com/v1/invoice/create'], [$request['method'], $request['url']]);
        self::assertSame(['X-API-KEY: '.self::API_KEY], $request['options']['normalized_headers']['x-api-key']);
        self::assertSame(['Accept: application/json'], $request['options']['normalized_headers']['accept']);
        $sent = $this->sentJson();
        self::assertSame(50_000, $sent['price'], 'Toman as is, an int');
        self::assertArrayNotHasKey('chat_id', $sent, 'off by default');

        // The callback URL: the notify path, the payment id and a 43-character base64url token.
        self::assertMatchesRegularExpression('#^https://shop\.example/payment/notify/tetraminator/4242/([A-Za-z0-9_-]{43})$#', $sent['callback_url']);
        preg_match('#/([A-Za-z0-9_-]{43})$#', $sent['callback_url'], $m);
        $token = $m[1];
        self::assertSame(hash('sha256', $token), $result->authority, 'only the hash is stored');
        self::assertStringNotContainsString($token, (string) json_encode([$result, $payment->getRequestPayload(), $payment->getVerifyPayload()]), 'the raw token is not stored');
        self::assertStringNotContainsString($token, (string) json_encode($result->rawResponse));

        self::assertSame([
            'status' => true,
            'message' => 'Invoice created',
            'pay_id' => self::PAY_ID,
        ], $result->rawResponse, 'allowlist only; mini_app_link only when use_mini_app_link is on');
        $this->assertNoSecret($result);
    }

    public function testCreateUsesPayableAmountAndAccepts200(): void
    {
        $this->respondCreated(status: 200);
        $payment = $this->payment(80_000)->setPayableAmount(60_000);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertTrue($result->success);
        self::assertSame(60_000, $this->sentJson()['price']);
        self::assertSame('60000', $payment->getExpectedAmount());
    }

    public function testCreateKeepsTlsVerificationAndRefusesRedirects(): void
    {
        $this->respondCreated();
        $payment = $this->payment();

        $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        $options = $this->requests[0]['options'];
        self::assertTrue($options['verify_peer']);
        self::assertTrue($options['verify_host']);
        self::assertSame(0, $options['max_redirects']);
        self::assertSame(5.0, $options['timeout']);
        self::assertSame(15.0, $options['max_duration']);
    }

    public function testChatIdOnlyWhenSwitchedOn(): void
    {
        $this->respondCreated();
        $this->respondCreated();
        $plugin = $this->plugin();
        $payment = $this->payment();

        $plugin->createPayment($payment, $payment->getOrder(), $this->config(['send_chat_id' => true]));
        $noAccount = $this->payment();
        $noAccount->getOrder()->getUser()->setTelegramAccount(null);
        $plugin->createPayment($noAccount, $noAccount->getOrder(), $this->config(['send_chat_id' => true]));

        self::assertSame(512345678, $this->sentJson(0)['chat_id']);
        self::assertArrayNotHasKey('chat_id', $this->sentJson(1), 'left out without a Telegram id');
        self::assertStringNotContainsString(self::TELEGRAM_ID, (string) json_encode($payment->getRequestPayload()));
    }

    public function testChatIdIsNotSentForATruthyNonBoolean(): void
    {
        $this->respondCreated();
        $payment = $this->payment();

        $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['send_chat_id' => 'yes']));

        self::assertArrayNotHasKey('chat_id', $this->sentJson());
    }

    #[DataProvider('amounts')]
    public function testCreateRangeLimits(int $amount, bool $accepted): void
    {
        if ($accepted) {
            $this->respondCreated();
        }
        $payment = $this->payment($amount);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertSame($accepted, $result->success);
        self::assertCount($accepted ? 1 : 0, $this->requests, 'no call on refusal');
        if (!$accepted) {
            self::assertSame(TetraminatorGatewayPlugin::CUSTOMER_AMOUNT_OUT_OF_RANGE, $result->message);
            self::assertSame(['error' => 'amount_out_of_range', 'amount' => $amount], $result->rawResponse);
            self::assertNull($payment->getExpectedAmount());
        }
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function amounts(): iterable
    {
        yield '49,999' => [49_999, false];
        yield '50,000' => [50_000, true];
        yield '10,000,000' => [10_000_000, true];
        yield '10,000,001' => [10_000_001, false];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('unusableConfigs')]
    public function testCreateRefusesAnUnusableConfigWithoutACall(array $overrides): void
    {
        $config = $this->config();
        foreach ($overrides as $key => $value) {
            if (null === $value) {
                unset($config[$key]);
            } else {
                $config[$key] = $value;
            }
        }
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $config);

        self::assertFalse($result->success);
        self::assertSame([], $this->requests);
    }

    public function testALiveInvoiceIsHandedOutAgainWithoutACall(): void
    {
        $payment = $this->issued();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertTrue($result->success);
        self::assertSame(self::LINK, $result->paymentUrl);
        self::assertSame(self::PAY_ID, $result->transactionId);
        self::assertNull($result->authority, 'the stored token hash stays');
        self::assertNull($result->rawResponse, 'the stored payload stays');
        self::assertSame([], $this->requests);
    }

    /**
     * @param \Closure(Payment): void $change
     */
    #[DataProvider('deadInvoices')]
    public function testAnInvoiceThatIsNotLiveGetsANewOne(\Closure $change): void
    {
        $this->respondCreated(['pay_id' => 'PAY_NEW']);
        $payment = $this->issued();
        $change($payment);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertTrue($result->success);
        self::assertSame('PAY_NEW', $result->transactionId);
        self::assertCount(1, $this->requests);
    }

    /**
     * @return iterable<string, array{\Closure(Payment): void}>
     */
    public static function deadInvoices(): iterable
    {
        yield 'expired' => [static function (Payment $p): void { $p->setExpiresAt(new \DateTimeImmutable('-1 second')); }];
        yield 'other amount' => [static function (Payment $p): void { $p->setAmount(60_000)->setPayableAmount(60_000); }];
        yield 'other gateway' => [static function (Payment $p): void { $p->getGateway()?->setPluginCode('winapay'); }];
        yield 'no expiry' => [static function (Payment $p): void { $p->setExpiresAt(null); }];
        yield 'no expectation' => [static function (Payment $p): void { $p->setExpectation(null, null); }];
    }

    /**
     * @param array<mixed> $overrides
     */
    #[DataProvider('linkChoices')]
    public function testLinkChoiceAndFallback(array $overrides, ?string $expected): void
    {
        $this->respondCreated($overrides);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertSame(null !== $expected, $result->success);
        self::assertSame($expected, $result->paymentUrl);
        if (null === $expected) {
            self::assertNull($result->transactionId);
            self::assertNull($payment->getExpiresAt(), 'a failed create sets no expiry');
        }
    }

    /**
     * @return iterable<string, array{array<mixed>, ?string}>
     */
    public static function linkChoices(): iterable
    {
        $payment = 'https://pay.tetraminator.example/p/'.self::PAY_ID;
        $gateway = 'https://gw.tetraminator.example/g/'.self::PAY_ID;
        yield 'url' => [[], self::LINK];
        yield 'no url: payment_link' => [['url' => null], $payment];
        yield 'http url: payment_link' => [['url' => 'http://pay.tetraminator.example/x'], $payment];
        yield 'only gateway_link' => [['url' => null, 'payment_link' => null, 'gateway_link' => $gateway], $gateway];
        yield 'no link at all' => [['url' => null, 'payment_link' => null], null];
        yield 'user info' => [['url' => 'https://u:p@pay.tetraminator.example/x', 'payment_link' => null], null];
        yield 'port' => [['url' => 'https://pay.tetraminator.example:8443/x', 'payment_link' => null], null];
        yield 'javascript' => [['url' => 'javascript:alert(1)', 'payment_link' => null], null];
        yield 'not a string' => [['url' => ['x'], 'payment_link' => 5], null];
        yield 'newline' => [['url' => "https://pay.tetraminator.example/x\nSet-Cookie: a", 'payment_link' => null], null];
    }

    public function testAnInvalidMiniAppLinkIsDropped(): void
    {
        $this->respondCreated(['mini_app_link' => 'http://t.me/x', 'callback_url' => 'https://shop.example/echo', 'chat_id' => 1]);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['use_mini_app_link' => true]));

        self::assertTrue($result->success);
        self::assertSame(['status', 'message', 'pay_id'], array_keys((array) $result->rawResponse));
    }

    /**
     * Every combination of the two link settings (missing = an existing config from before them) with an answer that has
     * or lacks gateway_link and mini_app_link.
     *
     * @return iterable<string, array{array<string, mixed>, bool, bool, string, bool}>
     */
    public static function linkSettings(): iterable
    {
        $settings = ['missing' => null, 'on' => true, 'off' => false];
        foreach ($settings as $preferName => $prefer) {
            foreach ($settings as $miniName => $mini) {
                foreach ([true, false] as $hasGateway) {
                    foreach ([true, false] as $hasMini) {
                        $config = array_filter(['prefer_website_link' => $prefer, 'use_mini_app_link' => $mini], static fn (?bool $v): bool => null !== $v);
                        $expectedUrl = $hasGateway && false !== $prefer ? self::GATEWAY_LINK : self::LINK;
                        $name = sprintf('prefer %s, mini app %s, %s gateway_link, %s mini_app_link', $preferName, $miniName, $hasGateway ? 'with' : 'no', $hasMini ? 'with' : 'no');
                        yield $name => [$config, $hasGateway, $hasMini, $expectedUrl, $hasMini && true === $mini];
                    }
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('linkSettings')]
    public function testTheAdminChoosesTheLink(array $settings, bool $hasGateway, bool $hasMini, string $expectedUrl, bool $storesMini): void
    {
        $this->respondCreated([
            'gateway_link' => $hasGateway ? self::GATEWAY_LINK : null,
            'mini_app_link' => $hasMini ? self::MINI_APP_LINK : null,
        ]);
        $payment = $this->payment();
        $config = $this->config($settings);
        self::assertArrayNotHasKey('prefer_website_link', $this->config(), 'the base config predates the settings');

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $config);

        self::assertTrue($result->success);
        self::assertSame($expectedUrl, $result->paymentUrl);
        $raw = (array) $result->rawResponse;
        if ($storesMini) {
            self::assertSame(self::MINI_APP_LINK, $raw['mini_app_link'] ?? null);
        } else {
            self::assertArrayNotHasKey('mini_app_link', $raw, 'not stored at all');
        }
    }

    public function testPreferWebsiteLinkOffKeepsTheOldOrder(): void
    {
        $this->respondCreated(['url' => null, 'gateway_link' => self::GATEWAY_LINK]);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['prefer_website_link' => false]));

        self::assertSame('https://pay.tetraminator.example/p/'.self::PAY_ID, $result->paymentUrl, 'payment_link before gateway_link');
    }

    /**
     * @return iterable<string, array{array<mixed>, ?string}>
     */
    public static function invalidGatewayLinks(): iterable
    {
        $paymentLink = 'https://pay.tetraminator.example/p/'.self::PAY_ID;
        foreach ([
            'http' => 'http://gw.tetraminator.example/g/1',
            'port' => 'https://gw.tetraminator.example:8443/g/1',
            'user info' => 'https://u:p@gw.tetraminator.example/g/1',
            'javascript' => 'javascript:alert(1)',
            'newline' => "https://gw.tetraminator.example/g/1\nX: y",
            'not a string' => ['x'],
            'empty' => '',
        ] as $name => $link) {
            yield $name.': url' => [['gateway_link' => $link], self::LINK];
            yield $name.': payment_link' => [['gateway_link' => $link, 'url' => null], $paymentLink];
            yield $name.': nothing left' => [['gateway_link' => $link, 'url' => null, 'payment_link' => null], null];
        }
    }

    /**
     * @param array<mixed> $overrides
     */
    #[DataProvider('invalidGatewayLinks')]
    public function testAnInvalidGatewayLinkFallsBack(array $overrides, ?string $expected): void
    {
        $this->respondCreated($overrides);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['prefer_website_link' => true]));

        self::assertSame(null !== $expected, $result->success);
        self::assertSame($expected, $result->paymentUrl);
    }

    public function testATruthyNonBooleanDoesNotStoreTheMiniAppLink(): void
    {
        $this->respondCreated();
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['use_mini_app_link' => 'yes']));

        self::assertArrayNotHasKey('mini_app_link', (array) $result->rawResponse);
    }

    public function testALiveInvoiceKeepsTheLinkChosenAtCreationAfterASettingsChange(): void
    {
        // Created with prefer_website_link off (url); the admin then turns it on: the open invoice is not re-asked.
        $payment = $this->issued();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config(['prefer_website_link' => true, 'use_mini_app_link' => true]));

        self::assertSame(self::LINK, $result->paymentUrl);
        self::assertNull($result->rawResponse, 'the stored payload stays as it was');
        self::assertSame([], $this->requests);
    }

    #[DataProvider('payIds')]
    public function testPayIdIsNormalisedAndValidated(mixed $payId, ?string $expected): void
    {
        $this->respondCreated(['pay_id' => $payId]);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertSame(null !== $expected, $result->success);
        self::assertSame($expected, $result->transactionId);
    }

    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function payIds(): iterable
    {
        yield 'string' => ['PAY_1-a', 'PAY_1-a'];
        yield 'int' => [987654, '987654'];
        yield 'float' => [12.5, null];
        yield 'bool' => [true, null];
        yield 'empty' => ['', null];
        yield 'path' => ['../x', null];
        yield 'too long' => [str_repeat('a', 129), null];
        yield 'missing' => [null, null];
    }

    /**
     * @param array<mixed> $overrides
     */
    #[DataProvider('createStatusFlags')]
    public function testCreateNeedsStatusStrictlyTrue(array $overrides): void
    {
        $this->respondCreated($overrides);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function createStatusFlags(): iterable
    {
        yield 'false' => [['status' => false]];
        yield '"true"' => [['status' => 'true']];
        yield '1' => [['status' => 1]];
        yield 'missing' => [['status' => null]];
    }

    #[DataProvider('createHttpErrors')]
    public function testCreateHttpErrors(int $status, string $error): void
    {
        $this->respond(['status' => false, 'message' => 'bad key '.self::API_KEY], $status);
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
        self::assertSame($error, $result->rawResponse['error'] ?? null);
        self::assertSame($status, $result->rawResponse['http_status'] ?? null);
        self::assertSame(TetraminatorGatewayPlugin::CUSTOMER_UNAVAILABLE, $result->message);
        self::assertNull($result->transactionId);
        self::assertCount(1, $this->requests, 'no retry');
        $this->assertNoSecret($result);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function createHttpErrors(): iterable
    {
        yield '400' => [400, 'http_400'];
        yield '401' => [401, 'unauthorized'];
        yield '403' => [403, 'store_disabled'];
        yield '404' => [404, 'http_404'];
        yield '429' => [429, 'http_429'];
        yield '500' => [500, 'http_500'];
        yield '503' => [503, 'http_503'];
    }

    public function testCreateMasksTheKeyAndTokenInAProviderMessage(): void
    {
        $this->responses[] = new MockResponse('', ['http_code' => 201]);
        $this->respond(['status' => false, 'message' => 'key='.self::API_KEY], 400);
        $plugin = $this->plugin();
        $payment = $this->payment();

        $empty = $plugin->createPayment($payment, $payment->getOrder(), $this->config());
        $refused = $plugin->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertSame(TetraminatorGatewayPlugin::CUSTOMER_UNAVAILABLE, $empty->message);
        self::assertSame(['error' => 'transport', 'detail' => 'Tetraminator returned an empty response (HTTP 201).'], $empty->rawResponse);
        self::assertSame('key=[redacted]', $refused->rawResponse['message'] ?? null);
        $this->assertNoSecret($empty, $refused);
    }

    #[DataProvider('brokenResponses')]
    public function testCreateTransportFailuresAreNotRetried(MockResponse|\Throwable $response, string $message): void
    {
        $this->responses[] = $response;
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
        self::assertSame(TetraminatorGatewayPlugin::CUSTOMER_UNAVAILABLE, $result->message);
        self::assertSame('transport', $result->rawResponse['error'] ?? null);
        self::assertStringContainsString($message, (string) ($result->rawResponse['detail'] ?? ''), 'the detail is for the admin');
        self::assertCount(1, $this->requests);
        $this->assertNoSecret($result);
    }

    // ---- 401 / 403 by status alone ---------------------------------------------------------------

    /**
     * @return iterable<string, array{int, string, MockResponse}>
     */
    public static function credentialAnswers(): iterable
    {
        foreach ([401 => 'unauthorized', 403 => 'store_disabled'] as $status => $error) {
            yield $status.' empty body' => [$status, $error, new MockResponse('', ['http_code' => $status])];
            yield $status.' HTML body' => [$status, $error, new MockResponse('<html><body>Forbidden</body></html>', ['http_code' => $status, 'response_headers' => ['content-type' => 'text/html']])];
            yield $status.' JSON body' => [$status, $error, new MockResponse('{"status":false,"message":"no"}', ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']])];
            yield $status.' JSON list body' => [$status, $error, new MockResponse('["x"]', ['http_code' => $status])];
            yield $status.' oversized body' => [$status, $error, new MockResponse(str_repeat('a', TetraminatorApi::MAX_RESPONSE_BYTES + 10), ['http_code' => $status])];
        }
    }

    #[DataProvider('credentialAnswers')]
    public function testCreateRecognises401And403WhateverTheBody(int $status, string $error, MockResponse $response): void
    {
        $this->responses[] = $response;
        $payment = $this->payment();

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config());

        self::assertFalse($result->success);
        self::assertSame(['error' => $error, 'http_status' => $status], array_intersect_key((array) $result->rawResponse, ['error' => 1, 'http_status' => 1]));
        self::assertSame(TetraminatorGatewayPlugin::CUSTOMER_UNAVAILABLE, $result->message);
        self::assertNull($result->transactionId);
    }

    #[DataProvider('credentialAnswers')]
    public function testInquiryRecognises401And403WhateverTheBody(int $status, string $error, MockResponse $response): void
    {
        $this->responses[] = $response;

        $result = $this->plugin()->verifyPayment($this->issued(), [], $this->config());

        self::assertFalse($result->success);
        self::assertFalse($result->paid);
        self::assertFalse($result->failed);
        self::assertSame(['error' => $error, 'http_status' => $status], $result->rawResponse);
    }

    // ---- what a failed create shows the customer --------------------------------------------------

    /**
     * Every way createPayment() can fail, each as [config overrides, amount, responses to queue].
     *
     * @return iterable<string, array{array<string, mixed>, int, list<MockResponse|\Throwable>}>
     */
    public static function createFailures(): iterable
    {
        $json = static fn (array $body, int $status = 201): MockResponse => new MockResponse((string) json_encode($body), ['http_code' => $status]);
        yield 'below the range' => [[], 49_999, []];
        yield 'above the range' => [[], 10_000_001, []];
        yield 'switched off' => [['enabled' => false], 50_000, []];
        yield 'no key' => [['api_key' => ''], 50_000, []];
        yield 'http callback' => [['callback_base_url' => 'http://shop.example'], 50_000, []];
        yield 'timeout' => [[], 50_000, [new MockResponse([''])]];
        yield 'TLS' => [[], 50_000, [new TransportException('SSL problem')]];
        yield 'HTML' => [[], 50_000, [new MockResponse('<html>x</html>', ['http_code' => 502])]];
        yield 'empty' => [[], 50_000, [new MockResponse('')]];
        yield 'oversized' => [[], 50_000, [new MockResponse(str_repeat('a', TetraminatorApi::MAX_RESPONSE_BYTES + 10))]];
        yield '400' => [[], 50_000, [$json(['status' => false, 'message' => 'amount too low'], 400)]];
        yield '401' => [[], 50_000, [new MockResponse('', ['http_code' => 401])]];
        yield '403' => [[], 50_000, [new MockResponse('', ['http_code' => 403])]];
        yield '429' => [[], 50_000, [$json(['status' => false], 429)]];
        yield '500' => [[], 50_000, [$json(['status' => false], 500)]];
        yield 'status false' => [[], 50_000, [$json(['status' => false, 'pay_id' => 'P1', 'url' => self::LINK])]];
        yield 'no pay_id' => [[], 50_000, [$json(['status' => true, 'url' => self::LINK])]];
        yield 'no link' => [[], 50_000, [$json(['status' => true, 'pay_id' => 'P1'])]];
    }

    /**
     * @param array<string, mixed>        $overrides
     * @param list<MockResponse|\Throwable> $responses
     */
    #[DataProvider('createFailures')]
    public function testEveryCreateFailureShowsTheCustomerAGenericPersianText(array $overrides, int $amount, array $responses): void
    {
        $this->responses = $responses;
        $payment = $this->payment($amount);

        $result = $this->plugin()->createPayment($payment, $payment->getOrder(), $this->config($overrides));

        self::assertFalse($result->success);
        $message = (string) $result->message;
        self::assertMatchesRegularExpression('/\p{Arabic}/u', $message, 'Persian');
        self::assertDoesNotMatchRegularExpression('/[A-Za-z0-9]/', $message, 'no config name, code or English word');
        self::assertDoesNotMatchRegularExpression('/api|key|http|enabled|callback/i', $message);
        self::assertIsString($result->rawResponse['error'] ?? null, 'the admin detail is in the payload');
        self::assertSame([], $this->responses, 'every queued answer was used');
    }

    // ---- verifyPayment ------------------------------------------------------------------------

    public function testVerifyConfirmsAnExactAnswerAndUsesTheStoredPayId(): void
    {
        $this->respond(['status' => true, 'payment_status' => 'paid', 'pay_id' => self::PAY_ID, 'amount' => 50_000]);
        $payment = $this->issued();

        $result = $this->plugin()->verifyPayment($payment, ['pay_id' => 'FORGED', 'amount' => '999999', 'status' => 'paid'], $this->config());

        self::assertSame(['GET', 'https://api.tetraminator.com/v1/payment/inquiry/'.self::PAY_ID], [$this->requests[0]['method'], $this->requests[0]['url']]);
        self::assertTrue($result->success);
        self::assertTrue($result->paid);
        self::assertFalse($result->failed);
        self::assertSame(['50000', 'IRT'], [$result->amount, $result->currency]);
        self::assertSame([self::PAY_ID, self::PAY_ID], [$result->transactionId, $result->refId]);
        self::assertSame(AmountMatchStatus::MATCH, $this->match($payment, $result));
        self::assertNull($payment->getVerifyPayload());
        $this->assertNoSecret($result);
    }

    #[DataProvider('exactAmounts')]
    public function testExactAmountForms(mixed $amount, mixed $payId): void
    {
        $this->respond(['status' => true, 'payment_status' => 'paid', 'pay_id' => $payId, 'amount' => $amount]);
        $payment = $this->issued()->setGatewayTransactionId((string) $payId);

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertSame(['50000', 'IRT'], [$result->amount, $result->currency]);
        self::assertSame(AmountMatchStatus::MATCH, $this->match($payment, $result));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function exactAmounts(): iterable
    {
        yield 'int amount, string pay_id' => [50_000, self::PAY_ID];
        yield 'digit string amount' => ['50000', self::PAY_ID];
        yield 'int pay_id' => [50_000, 123456];
    }

    /**
     * Claims paid, a rule fails: paid with no amount (AmountMatcher: missing, never confirmed), evidence in the verify
     * payload.
     *
     * @param array<mixed> $overrides
     */
    #[DataProvider('failedRules')]
    public function testAPaidClaimThatFailsARuleGoesToReview(array $overrides, string $reason, ?string $reportedAmount, string $payIdMatch): void
    {
        $this->respond(array_replace(['status' => true, 'payment_status' => 'paid', 'pay_id' => self::PAY_ID, 'amount' => 50_000], $overrides));
        $payment = $this->issued();

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertTrue($result->success);
        self::assertTrue($result->paid);
        self::assertFalse($result->failed);
        self::assertNull($result->amount, 'never the expected amount, never a higher one');
        self::assertNull($result->currency);
        self::assertSame(AmountMatchStatus::MISSING, $this->match($payment, $result));
        $evidence = $payment->getVerifyPayload()[TetraminatorGatewayPlugin::REVIEW_EVIDENCE_KEY] ?? null;
        self::assertIsArray($evidence);
        self::assertSame($reason, $evidence['reason']);
        self::assertSame($reportedAmount, $evidence['reported_amount']);
        self::assertSame($payIdMatch, $evidence['pay_id_match']);
        self::assertSame('50000', $evidence['expected_amount']);
        $this->assertNoSecret($result, $payment->getVerifyPayload());
    }

    /**
     * @return iterable<string, array{array<mixed>, string, ?string, string}>
     */
    public static function failedRules(): iterable
    {
        yield 'status "true"' => [['status' => 'true'], 'status_not_true', '50000', 'yes'];
        yield 'status 1' => [['status' => 1], 'status_not_true', '50000', 'yes'];
        yield 'status false' => [['status' => false], 'status_not_true', '50000', 'yes'];
        yield 'payment_status PAID' => [['payment_status' => 'PAID'], 'payment_status_not_exact', '50000', 'yes'];
        yield 'payment_status Paid' => [['payment_status' => ' Paid'], 'payment_status_not_exact', '50000', 'yes'];
        yield 'amount 50000.0' => [['amount' => 50000.0], 'amount_not_integer', '50000.0', 'yes'];
        yield 'amount "50000.0"' => [['amount' => '50000.0'], 'amount_not_integer', '50000.0', 'yes'];
        yield 'amount negative' => [['amount' => -50_000], 'amount_not_integer', '-50000', 'yes'];
        yield 'amount null' => [['amount' => null], 'amount_missing', null, 'yes'];
        yield 'amount "50,000"' => [['amount' => '50,000'], 'amount_not_integer', '50,000', 'yes'];
        yield 'amount array' => [['amount' => [50_000]], 'amount_not_integer', '[50000]', 'yes'];
        yield 'amount higher' => [['amount' => 60_000], 'amount_mismatch', '60000', 'yes'];
        yield 'amount lower' => [['amount' => 49_000], 'amount_mismatch', '49000', 'yes'];
        yield 'amount in Rial' => [['amount' => 500_000], 'amount_mismatch', '500000', 'yes'];
        yield 'other pay_id' => [['pay_id' => 'PAY_OTHER'], 'pay_id_mismatch', '50000', 'no'];
        yield 'missing pay_id' => [['pay_id' => null], 'pay_id_missing', '50000', 'no'];
        yield 'int pay_id, other' => [['pay_id' => 1], 'pay_id_mismatch', '50000', 'no'];
    }

    public function testTheExpectationFrozenAtInvoiceTimeDecides(): void
    {
        $this->respond(['status' => true, 'payment_status' => 'paid', 'pay_id' => self::PAY_ID, 'amount' => 60_000]);
        // The order's amount changed after the invoice: the invoice's own amount is what was asked for.
        $payment = $this->issued()->setAmount(60_000)->setPayableAmount(60_000);

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertNull($result->amount);
        self::assertSame('amount_mismatch', $payment->getVerifyPayload()[TetraminatorGatewayPlugin::REVIEW_EVIDENCE_KEY]['reason'] ?? null);
    }

    /**
     * @param array<mixed> $json
     */
    #[DataProvider('notPaidAnswers')]
    public function testNotPaidAnswers(array $json, int $httpStatus, string $message): void
    {
        $this->respond($json, $httpStatus);
        $payment = $this->issued();

        $result = $this->plugin()->verifyPayment($payment, [], $this->config());

        self::assertTrue($result->success, 'a well-formed answer');
        self::assertFalse($result->paid);
        self::assertFalse($result->failed, 'never rejected: the invoice may still be paid');
        self::assertStringContainsString($message, (string) $result->message);
        self::assertNull($payment->getVerifyPayload());
    }

    /**
     * @return iterable<string, array{array<mixed>, int, string}>
     */
    public static function notPaidAnswers(): iterable
    {
        yield 'pending' => [['status' => true, 'payment_status' => 'pending', 'pay_id' => self::PAY_ID], 200, 'payment_status "pending"'];
        yield 'expired' => [['status' => true, 'payment_status' => 'expired'], 200, 'payment_status "expired"'];
        yield 'no payment_status' => [['status' => true], 200, 'payment_status missing'];
        yield 'odd payment_status' => [['status' => true, 'payment_status' => ['x' => 1]], 200, '[unrecognised array]'];
        yield 'long payment_status' => [['status' => true, 'payment_status' => str_repeat('x', 40).self::API_KEY], 200, '[unrecognised string]'];
        yield 'status false' => [['status' => false, 'message' => 'not paid'], 200, 'status false'];
        yield '404 JSON' => [['status' => false, 'message' => 'not found'], 404, 'HTTP 404'];
    }

    #[DataProvider('verifyHttpErrors')]
    public function testVerifyHttpErrorsDecideNothing(int $status, ?string $error): void
    {
        $this->respond(['status' => true, 'payment_status' => 'paid', 'pay_id' => self::PAY_ID, 'amount' => 50_000, 'api_key' => self::API_KEY], $status);

        $result = $this->plugin()->verifyPayment($this->issued(), [], $this->config());

        self::assertFalse($result->success);
        self::assertFalse($result->paid, 'a paid body on an error status is not believed');
        self::assertFalse($result->failed);
        if (null !== $error) {
            self::assertSame($error, $result->rawResponse['error'] ?? null);
        }
        self::assertCount(1, $this->requests, 'no retry');
        $this->assertNoSecret($result);
    }

    /**
     * @return iterable<string, array{int, ?string}>
     */
    public static function verifyHttpErrors(): iterable
    {
        yield '400' => [400, null];
        yield '401' => [401, 'unauthorized'];
        yield '403' => [403, 'store_disabled'];
        yield '429' => [429, null];
        yield '500' => [500, null];
        yield '502' => [502, null];
        yield '201' => [201, null];
        yield '302 with JSON' => [302, null];
    }

    public function testAMalformedStatusIsAnError(): void
    {
        $this->respond(['status' => 'yes', 'payment_status' => 'pending']);

        $result = $this->plugin()->verifyPayment($this->issued(), [], $this->config());

        self::assertFalse($result->success);
        self::assertFalse($result->paid);
    }

    #[DataProvider('brokenResponses')]
    public function testVerifyTransportFailures(MockResponse|\Throwable $response, string $message): void
    {
        $this->responses[] = $response;

        $result = $this->plugin()->verifyPayment($this->issued(), [], $this->config());

        self::assertFalse($result->success);
        self::assertFalse($result->paid);
        self::assertFalse($result->failed);
        self::assertStringContainsString($message, (string) $result->message);
        self::assertStringNotContainsString(self::PAY_ID, (string) $result->message, 'the transport text names the URL: not passed on');
        $this->assertNoSecret($result);
    }

    /**
     * @return iterable<string, array{MockResponse|\Throwable, string}>
     */
    public static function brokenResponses(): iterable
    {
        yield 'HTML' => [new MockResponse('<!DOCTYPE html><html>Maintenance</html>', ['http_code' => 503]), 'HTML page'];
        yield 'HTML 404' => [new MockResponse('<html>Not Found</html>', ['http_code' => 404]), 'HTML page instead of JSON (HTTP 404)'];
        yield 'plain text' => [new MockResponse('status=true'), 'invalid response'];
        yield 'JSON list' => [new MockResponse('[true]'), 'invalid response'];
        yield 'empty' => [new MockResponse(''), 'empty response'];
        yield 'redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'https://other.example/']]), 'empty response (HTTP 302)'];
        yield 'oversized' => [new MockResponse('{"status":true,"x":"'.str_repeat('a', TetraminatorApi::MAX_RESPONSE_BYTES).'"}'), 'too large'];
        yield 'timeout' => [new MockResponse(['']), 'did not respond in time'];
        yield 'TLS' => [new TransportException('SSL certificate problem for https://api.tetraminator.com/v1/payment/inquiry/'.self::PAY_ID.' key '.self::API_KEY), 'network or TLS error'];
    }

    public function testVerifyNeedsTheKeyAndAStoredPayIdAndIgnoresThePayload(): void
    {
        $plugin = $this->plugin();

        $noKey = $plugin->verifyPayment($this->issued(), [], $this->config(['api_key' => '']));
        $noPayId = $plugin->verifyPayment($this->payment(), ['pay_id' => self::PAY_ID], $this->config());
        $badPayId = $plugin->verifyPayment($this->issued()->setGatewayTransactionId('../admin'), [], $this->config());

        foreach ([$noKey, $noPayId, $badPayId] as $result) {
            self::assertFalse($result->success);
            self::assertFalse($result->paid);
        }
        self::assertSame([], $this->requests);
    }

    public function testVerifyStillWorksWithTheSwitchOff(): void
    {
        $this->respond(['status' => true, 'payment_status' => 'paid', 'pay_id' => self::PAY_ID, 'amount' => 50_000]);

        $result = $this->plugin()->verifyPayment($this->issued(), [], $this->config(['enabled' => false, 'callback_base_url' => '']));

        self::assertSame('50000', $result->amount, 'the switch gates new invoices only');
    }

    public function testTheKeyNeverReachesAStoredResponse(): void
    {
        $this->respond(['status' => true, 'payment_status' => 'paid', 'pay_id' => self::PAY_ID, 'amount' => 50_000, 'X-API-KEY' => self::API_KEY, 'echo' => ['note' => 'used '.self::API_KEY, 'apiKey' => 'x']]);

        $result = $this->plugin()->verifyPayment($this->issued(), [], $this->config());

        self::assertTrue($result->paid);
        self::assertSame('[redacted]', $result->rawResponse['X-API-KEY'] ?? null);
        self::assertSame('used [redacted]', $result->rawResponse['echo']['note'] ?? null);
        $this->assertNoSecret($result);
    }

    public function testVerifyTwiceIsARepeatableRead(): void
    {
        $answer = ['status' => true, 'payment_status' => 'paid', 'pay_id' => self::PAY_ID, 'amount' => 50_000];
        $this->respond($answer);
        $this->respond($answer);
        $payment = $this->issued();
        $plugin = $this->plugin();

        $first = $plugin->verifyPayment($payment, [], $this->config());
        $second = $plugin->verifyPayment($payment, [], $this->config());

        self::assertSame(AmountMatchStatus::MATCH, $this->match($payment, $first));
        self::assertSame(AmountMatchStatus::MATCH, $this->match($payment, $second), 'settle() makes the second a no-op');
    }

    // ---- through the core adapter ----------------------------------------------------------------

    public function testTheAdapterExposesTheConstraints(): void
    {
        $adapter = new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode(TetraminatorGatewayPlugin::CODE), $this->plugin());

        self::assertTrue($adapter->isUsable($this->config()));
        self::assertFalse($adapter->isUsable($this->config(['enabled' => false])));
        self::assertFalse($adapter->acceptsAmount(49_999));
        self::assertSame(3600, $adapter->invoiceLifetimeSeconds());
    }

    public function testNoGenericWebhookOrCallbackDecidesAnything(): void
    {
        $gateway = (new PaymentGateway())->setType('plugin')->setPluginCode(TetraminatorGatewayPlugin::CODE)->setConfig($this->config());
        $adapter = new PluginPaymentGatewayDriverAdapter((new Plugin())->setCode(TetraminatorGatewayPlugin::CODE), $this->plugin());

        $webhook = $adapter->handleNotification($gateway, Request::create('/payment/webhook/plugin/tetraminator', 'POST', ['pay_id' => self::PAY_ID]), NotificationAwareGatewayInterface::CHANNEL_WEBHOOK);

        self::assertFalse($this->plugin()->supportsWebhook());
        self::assertFalse($webhook->authenticated);
        self::assertNull($this->plugin()->handleWebhook([], Request::create('/'), $this->config()));
    }

    public function testManifestMatchesThePluginClass(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__).'/plugin.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $manifest['manifestVersion']);
        self::assertSame('tetraminator', $manifest['code']);
        self::assertSame(TetraminatorGatewayPlugin::class, $manifest['mainClass']);
        self::assertSame((new TetraminatorGatewayPlugin())->getType(), $manifest['code']);
        self::assertSame('payment', $manifest['category']);
        self::assertFalse($manifest['supportsWebhook']);
        self::assertTrue($manifest['supportsOnlinePayment']);
        self::assertSame(['payment.create', 'payment.verify', 'http.outbound'], $manifest['permissions']);
        $fields = array_column($manifest['configSchema'], null, 'key');
        self::assertSame(['enabled', 'api_key', 'callback_base_url', 'send_chat_id', 'prefer_website_link', 'use_mini_app_link'], array_keys($fields));
        self::assertSame(['boolean', false, false], [$fields['enabled']['type'], $fields['enabled']['required'], $fields['enabled']['default']]);
        self::assertSame(['password', true], [$fields['api_key']['type'], $fields['api_key']['required']]);
        self::assertArrayNotHasKey('default', $fields['api_key'], 'no fake secret default');
        self::assertSame(['url', true], [$fields['callback_base_url']['type'], $fields['callback_base_url']['required']]);
        self::assertSame(['boolean', false, false], [$fields['send_chat_id']['type'], $fields['send_chat_id']['required'], $fields['send_chat_id']['default']]);
        self::assertSame(['boolean', false, true], [$fields['prefer_website_link']['type'], $fields['prefer_website_link']['required'], $fields['prefer_website_link']['default']]);
        self::assertSame(['boolean', false, false], [$fields['use_mini_app_link']['type'], $fields['use_mini_app_link']['required'], $fields['use_mini_app_link']['default']]);
        self::assertArrayNotHasKey('underpayment_tolerance_percent', $fields);
    }

    public function testNoRealKeyIsShipped(): void
    {
        $files = array_merge(glob(dirname(__DIR__).'/src/*.php') ?: [], [dirname(__DIR__).'/plugin.json', dirname(__DIR__).'/README.md']);
        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/X-API-KEY:\s*(?!<API_KEY>)[A-Za-z0-9]{16,}/', $text, basename($file));
        }
    }
}
