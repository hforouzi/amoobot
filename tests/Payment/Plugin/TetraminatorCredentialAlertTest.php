<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use Amoobot\Plugin\Tetraminator\TetraminatorGatewayPlugin;
use App\Payment\Application\AdminPaymentAlerter;
use App\Payment\Application\PaymentGatewayCredentialAlert;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The Tetraminator plugin's 401 / 403 at create, whatever the body (empty, HTML, JSON), reaches the core's credential
 * alert: the plugin reports rawResponse.error unauthorized / store_disabled, PaymentGatewayCredentialAlert sends the
 * admin alert with no key. (PaymentStarter's call of the alert: GatewayCredentialAlertTest.)
 */
final class TetraminatorCredentialAlertTest extends IntegrationTestCase
{
    private const API_KEY = 'FAKE-tetraminator-key-for-tests-0000';
    /** TELEGRAM_ADMIN_CHAT_ID in .env.test. */
    private const ADMIN_CHAT = '999000';

    public static function setUpBeforeClass(): void
    {
        foreach (['TetraminatorException', 'TetraminatorApi', 'TetraminatorGatewayPlugin'] as $class) {
            require_once dirname(__DIR__, 3).'/docs/plugins/tetraminator-gateway/src/'.$class.'.php';
        }
    }

    /**
     * @return iterable<string, array{int, MockResponse, string}>
     */
    public static function answers(): iterable
    {
        foreach ([401 => 'API key missing or rejected', 403 => 'API key invalid or store disabled'] as $status => $text) {
            yield $status.' empty' => [$status, new MockResponse('', ['http_code' => $status]), $text];
            yield $status.' HTML' => [$status, new MockResponse('<html><body>'.$status.'</body></html>', ['http_code' => $status, 'response_headers' => ['content-type' => 'text/html']]), $text];
            yield $status.' JSON' => [$status, new MockResponse('{"status":false,"message":"key '.self::API_KEY.'"}', ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]), $text];
        }
    }

    #[DataProvider('answers')]
    public function testA401Or403AtCreateAlertsTheAdmins(int $status, MockResponse $answer, string $text): void
    {
        $gateway = $this->createGateway('plugin', ['enabled' => true, 'api_key' => self::API_KEY, 'callback_base_url' => 'https://shop.example'], 'IRT')->setPluginCode(TetraminatorGatewayPlugin::CODE);
        $this->em->flush();
        $payment = $this->createPayment($this->createOrder($this->createUser(), $this->createPlan(150000)), $gateway, PaymentStatus::PENDING);
        /** @var RateLimiterFactory $limiter */
        $limiter = self::getContainer()->get('limiter.payment_gateway_credential_alert');
        $limiter->create('gateway_'.$gateway->getId())->reset();
        $plugin = new TetraminatorGatewayPlugin(new MockHttpClient([$answer]));

        $result = $plugin->createPayment($payment, $payment->getOrder(), (array) $gateway->getConfig());
        self::getContainer()->get(PaymentGatewayCredentialAlert::class)->afterFailedCreate($payment, $result->rawResponse);

        self::assertFalse($result->success);
        self::assertSame(401 === $status ? 'unauthorized' : 'store_disabled', $result->rawResponse['error'] ?? null);
        $alerts = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = json_decode((string) ($request['options']['body'] ?? ''), true);
            if (is_array($body) && self::ADMIN_CHAT === (string) ($body['chat_id'] ?? '') && str_starts_with((string) ($body['text'] ?? ''), AdminPaymentAlerter::GATEWAY_ACCOUNT_REFUSED_TITLE)) {
                $alerts[] = (string) $body['text'];
            }
        }
        self::assertCount(1, $alerts);
        self::assertStringContainsString($text, $alerts[0]);
        self::assertStringNotContainsString(self::API_KEY, implode("\n", $this->http->telegramTexts()));
    }
}
