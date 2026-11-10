<?php

declare(strict_types=1);

namespace App\Tests\Payment\Plugin;

use Amoobot\Plugin\ConstrainedGateway\InvoicingGatewayPlugin;
use App\Entity\StorePaymentMethod;
use App\Payment\Application\AdminPaymentAlerter;
use App\Shop\Application\Checkout\PaymentStarter;
use App\Tests\Support\ConstrainedGatewayFixture;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * A gateway that refuses to create a payment because of its account (rawResponse.error unauthorized / store_disabled,
 * e.g. Tetraminator's 401 / 403) alerts the admins through AdminPaymentAlerter, at most once an hour per gateway, with a
 * fixed text that never holds the key (PaymentGatewayCredentialAlert). Any other failure alerts nobody.
 */
final class GatewayCredentialAlertTest extends IntegrationTestCase
{
    use ConstrainedGatewayFixture;

    /** TELEGRAM_ADMIN_CHAT_ID in .env.test. */
    private const ADMIN_CHAT = '999000';

    private StorePaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installConstrainedPlugin(InvoicingGatewayPlugin::class);
        $this->method = $this->constrainedMethod();
        $this->resetLimiter($this->method);
        self::getContainer()->get(RecordingLogger::class)->records = [];
    }

    protected function tearDown(): void
    {
        $this->tearDownConstrainedGateway();
        parent::tearDown();
    }

    private function resetLimiter(StorePaymentMethod $method): void
    {
        /** @var RateLimiterFactory $limiter */
        $limiter = self::getContainer()->get('limiter.payment_gateway_credential_alert');
        $limiter->create('gateway_'.$method->getGateway()->getId())->reset();
    }

    private function start(StorePaymentMethod $method): bool
    {
        $order = $this->createOrder($this->createUser(), $this->createPlan(150000));

        return self::getContainer()->get(PaymentStarter::class)->start($order, $method)->success;
    }

    /**
     * The alerts the TELEGRAM_ADMIN_CHAT_ID admin got (linked admins left by other tests get their own copy).
     *
     * @return list<string>
     */
    private function alerts(): array
    {
        $texts = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = $request['options']['body'] ?? null;
            $body = is_string($body) ? json_decode($body, true) : null;
            if (!is_array($body)) {
                continue;
            }
            $text = (string) ($body['text'] ?? '');
            if (self::ADMIN_CHAT === (string) ($body['chat_id'] ?? '') && str_starts_with($text, AdminPaymentAlerter::GATEWAY_ACCOUNT_REFUSED_TITLE)) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    public function testAnInvalidKeyAlertsTheAdminsOnceAnHourPerGateway(): void
    {
        InvoicingGatewayPlugin::$answer = 'unauthorized';

        self::assertFalse($this->start($this->method));
        self::assertFalse($this->start($this->method));
        self::assertFalse($this->start($this->method));

        $alerts = $this->alerts();
        self::assertCount(1, $alerts, 'once per hour for this gateway, however many customers fail');
        self::assertStringContainsString('API key missing or rejected', $alerts[0]);
        self::assertStringContainsString('gateway #'.$this->method->getGateway()->getId(), $alerts[0]);
        self::assertStringNotContainsString(self::API_KEY, implode("\n", $this->http->telegramTexts()), 'the provider text quoted the key: the alert does not');
        self::assertStringNotContainsString(self::API_KEY, self::getContainer()->get(RecordingLogger::class)->text());

        $other = $this->constrainedMethod([], 'دومین درگاه', 60);
        $this->resetLimiter($other);
        self::assertFalse($this->start($other));
        self::assertCount(2, $this->alerts(), 'another gateway has its own hour');
    }

    public function testADisabledStoreAlertsWithItsOwnText(): void
    {
        InvoicingGatewayPlugin::$answer = 'store_disabled';

        self::assertFalse($this->start($this->method));

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertStringContainsString('API key invalid or store disabled', $alerts[0]);
        self::assertStringNotContainsString(self::API_KEY, $alerts[0]);
    }

    public function testOtherFailuresAndSuccessesAlertNobody(): void
    {
        InvoicingGatewayPlugin::$answer = 'fail';
        self::assertFalse($this->start($this->method));
        InvoicingGatewayPlugin::$answer = null;
        self::assertTrue($this->start($this->method));

        self::assertSame([], $this->alerts());
    }
}
