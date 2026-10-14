<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\UI\TelegramWebhookController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The webhook needs both the URL secret and the X-Telegram-Bot-Api-Secret-Token header (setWebhook secret_token).
 * Bodies here are not JSON, so an accepted request returns ok without touching the update handler or the database.
 */
final class TelegramWebhookSecretTokenTest extends WebTestCase
{
    private const URL_SECRET = 'url-secret_for-tests';
    private const HEADER_TOKEN = 'header-token_for-tests';

    protected function setUp(): void
    {
        $_SERVER['TELEGRAM_WEBHOOK_SECRET'] = $_ENV['TELEGRAM_WEBHOOK_SECRET'] = self::URL_SECRET;
        $_SERVER['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = $_ENV['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = self::HEADER_TOKEN;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SERVER['TELEGRAM_WEBHOOK_SECRET_TOKEN'], $_ENV['TELEGRAM_WEBHOOK_SECRET_TOKEN']);
    }

    public function testAcceptsMatchingUrlSecretAndHeaderToken(): void
    {
        self::assertSame(200, $this->post(self::URL_SECRET, self::HEADER_TOKEN));
    }

    public function testRejectsMissingHeaderToken(): void
    {
        self::assertSame(403, $this->post(self::URL_SECRET, null));
    }

    public function testRejectsWrongHeaderToken(): void
    {
        self::assertSame(403, $this->post(self::URL_SECRET, 'wrong-token'));
    }

    public function testRejectsWrongUrlSecretEvenWithValidHeader(): void
    {
        self::assertSame(403, $this->post('wrong-secret', self::HEADER_TOKEN));
    }

    public function testHeaderTokenFallsBackToUrlSecret(): void
    {
        self::assertSame('own-token', TelegramWebhookController::effectiveSecretToken('own-token', 'url'));
        self::assertSame('url', TelegramWebhookController::effectiveSecretToken('', 'url'));
    }

    public function testUnsetHeaderSecretRejectsMissingHeader(): void
    {
        unset($_SERVER['TELEGRAM_WEBHOOK_SECRET_TOKEN'], $_ENV['TELEGRAM_WEBHOOK_SECRET_TOKEN']);

        self::assertSame(403, $this->post(self::URL_SECRET, null));
    }

    public function testEmptyHeaderSecretRejectsEmptyHeader(): void
    {
        $_SERVER['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = $_ENV['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = '';

        self::assertSame(403, $this->post(self::URL_SECRET, ''));
    }

    public function testEmptyHeaderSecretAcceptsMatchingUrlSecretHeader(): void
    {
        $_SERVER['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = $_ENV['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = '';

        self::assertSame(200, $this->post(self::URL_SECRET, self::URL_SECRET));
    }

    public function testEmptyUrlSecretRejectsRequest(): void
    {
        $_SERVER['TELEGRAM_WEBHOOK_SECRET'] = $_ENV['TELEGRAM_WEBHOOK_SECRET'] = '';
        $_SERVER['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = $_ENV['TELEGRAM_WEBHOOK_SECRET_TOKEN'] = '';

        self::assertSame(403, $this->post(self::URL_SECRET, ''));
    }

    private function post(string $urlSecret, ?string $headerToken): int
    {
        $client = self::createClient();
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $headerToken) {
            $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $headerToken;
        }
        $client->request('POST', '/telegram/webhook/'.$urlSecret, [], [], $server, 'not-json');

        return $client->getResponse()->getStatusCode();
    }
}
