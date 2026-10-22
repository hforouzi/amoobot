<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Drives the Mini App like the Telegram WebView does: POST initData to /app/auth, then call /app/* with the session
 * token (Authorization: Bearer) and the CSRF token (X-CSRF-Token). For IntegrationTestCase subclasses.
 */
trait MiniAppTestTrait
{
    protected const UA = 'Mozilla/5.0 (Linux; Android 14) Telegram-Android/11.2';

    protected KernelBrowser $client;

    protected function startMiniAppClient(): void
    {
        // The per-Telegram-user login limit (10 per 5 min) would otherwise carry over between runs for fixed test ids.
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        // Every test from its own address: limiter state kept in the cache between runs cannot leak in.
        $this->client->setServerParameter('REMOTE_ADDR', '10.88.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->client->setServerParameter('HTTP_USER_AGENT', self::UA);
    }

    protected function botToken(): string
    {
        return (string) ($_SERVER['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
    }

    protected function initData(string $telegramId, ?int $authDate = null, string $firstName = 'Sara'): string
    {
        return TelegramInitData::signed($this->botToken(), TelegramInitData::user($telegramId, $firstName), $authDate);
    }

    protected function postAuth(string $initData): void
    {
        $this->client->request('POST', '/app/auth', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['initData' => $initData], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{token: string, csrf: string}
     */
    protected function authenticate(string $telegramId, string $firstName = 'Sara'): array
    {
        $this->postAuth($this->initData($telegramId, null, $firstName));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return ['token' => (string) $body['token'], 'csrf' => (string) $body['csrfToken']];
    }

    /**
     * @param array{token: string, csrf: string}|null $session
     */
    protected function appGet(string $path, ?array $session): string
    {
        $this->client->request('GET', $path, [], [], null === $session ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$session['token']]);

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @param array{token: string, csrf: string}|null $session
     */
    protected function appPost(string $path, ?array $session, ?string $csrf = null): string
    {
        $server = null === $session ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$session['token']];
        $csrf ??= $session['csrf'] ?? null;
        if (null !== $csrf) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        $this->client->request('POST', $path, [], [], $server);

        return (string) $this->client->getResponse()->getContent();
    }

    protected function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);

        return $data;
    }
}
