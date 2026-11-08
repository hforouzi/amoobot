<?php

declare(strict_types=1);

namespace App\Tests\Payment\Notify;

use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\TetraminatorNotifyFixtures;
use App\Tests\Support\WinapayReconcileFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The generic plugin routes are closed for a plugin with its own endpoint (design.md decision 2, "Closing the generic
 * route"): otherwise /payment/callback/plugin/tetraminator?transaction_id=<pay_id> would reach verifyPayment() with no
 * token and no rate limit, and answer with the outcome. Other plugins keep them.
 */
final class GenericPluginRoutesTest extends IntegrationTestCase
{
    use TetraminatorNotifyFixtures;
    use WinapayReconcileFixtures;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        // Not followed: a redirect to the return page would itself be an answer about the payment.
        $this->client->followRedirects(false);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function genericRoutes(): iterable
    {
        foreach (['tetraminator', 'Tetraminator', ' TETRAMINATOR'] as $code) {
            yield 'GET callback '.$code => ['GET', '/payment/callback/plugin/'.rawurlencode($code)];
            yield 'POST callback '.$code => ['POST', '/payment/callback/plugin/'.rawurlencode($code)];
            yield 'POST webhook alias '.$code => ['POST', '/payment/webhook/plugin/'.rawurlencode($code)];
            if ($code === trim($code)) {
                // With a space the unified route's code requirement does not match: the router answers 405 itself.
                yield 'GET unified webhook '.$code => ['GET', '/payment/webhook/plugin/'.rawurlencode($code)];
            }
        }
        yield 'POST unified webhook (the alias route has priority, so this is the same path)' => ['POST', '/payment/webhook/plugin/tetraminator'];
    }

    #[DataProvider('genericRoutes')]
    public function testTetraminatorGetsA404BeforeAnyLookupOrVerification(string $method, string $path): void
    {
        $this->installTetraminator();
        $payment = $this->tetraminatorPayment();
        $this->tetraminatorAnswers([self::tetraPaid()]);
        $hints = ['transaction_id' => self::TETRA_PAY_ID, 'transactionId' => self::TETRA_PAY_ID, 'authority' => hash('sha256', self::TETRA_TOKEN), 'payment_id' => (string) $payment->getId(), 'rt' => (string) $payment->getReturnToken()];

        $this->client->request($method, $path.'?'.http_build_query($hints), 'POST' === $method ? $hints : []);

        $response = $this->client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($response->isRedirection(), 'the return token is not looked up');
        self::assertStringNotContainsString((string) $payment->getReturnToken(), (string) $response->getContent());
        self::assertSame([], $this->tetraminatorCalls(), 'no inquiry');
        self::assertSame(PaymentStatus::PENDING, $this->em->getConnection()->fetchOne('SELECT status FROM payment WHERE id = ?', [$payment->getId()]));
    }

    public function testTheTypedWebhookRouteIsClosedForTetraminatorToo(): void
    {
        $this->installTetraminator();
        $payment = $this->tetraminatorPayment();
        $this->tetraminatorAnswers([self::tetraPaid()]);

        // GET reaches the unified route /payment/webhook/{type}/{code} with type "plugin" (POST is the alias's).
        $this->client->request('GET', '/payment/webhook/plugin/tetraminator', ['transaction_id' => self::TETRA_PAY_ID]);

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(['ok' => false, 'message' => 'gateway_not_found'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame([], $this->tetraminatorCalls());
        self::assertSame(PaymentStatus::PENDING, $this->em->getConnection()->fetchOne('SELECT status FROM payment WHERE id = ?', [$payment->getId()]));
    }

    public function testWinapayStillSettlesThroughTheGenericCallbackRoute(): void
    {
        $this->installWinapay();
        $payment = $this->winapayPayment();
        $this->winapayAnswers([self::paid()]);

        $this->client->request('POST', '/payment/callback/plugin/winapay', ['Authority' => self::AUTHORITY, 'PaymentStatus' => 'OK']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $this->winapayCalls());
        self::assertSame(PaymentStatus::CONFIRMED, $this->em->getConnection()->fetchOne('SELECT status FROM payment WHERE id = ?', [$payment->getId()]));
    }

    public function testOtherPluginsStillReachThePipelineOnTheWebhookRoutes(): void
    {
        $this->installWinapay();
        $this->winapayPayment();

        foreach (['POST', 'GET'] as $method) {
            $this->client->request($method, '/payment/webhook/plugin/winapay', ['Authority' => self::AUTHORITY]);
            $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

            self::assertNotSame('gateway_not_found', $answer['message'] ?? null, $method.': the gateway is found, the plugin decides');
        }
    }
}
