<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\AdminUser;
use App\Entity\TelegramAccount;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\TelegramInitData;

/**
 * /app/auth (initData -> server-side session) and the session itself: hashed token, 12 h expiry, bound to the
 * Telegram user and the user agent, revocable, CSRF on every state-changing request, rate limits, and the admin
 * identity resolved on every request (a deactivated admin loses admin rights at once).
 */
final class MiniAppAuthTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM mini_app_session');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->startMiniAppClient();
    }

    public function testValidInitDataOpensASessionWithAHashedRandomToken(): void
    {
        $session = $this->authenticate('8101');
        $response = $this->client->getResponse();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $session['token'], '32 random bytes (256 bits), base64url');
        self::assertNotSame('', $session['csrf']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies(), 'the token travels in the Authorization header, not a cookie');

        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM mini_app_session');
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $session['token']), $row['token_hash'], 'only the hash is stored');
        self::assertNotContains($session['token'], array_map('strval', $row));
        self::assertSame('8101', $row['telegram_user_id']);
        self::assertSame(hash('sha256', self::UA), $row['user_agent_hash']);
        $ttl = strtotime((string) $row['expires_at']) - strtotime((string) $row['created_at']);
        self::assertSame(12 * 3600, $ttl, '12 hour session');

        $this->appGet('/app/api/me', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['firstName' => 'Sara', 'isAdmin' => false], $this->json());
    }

    public function testTwoSessionsGetDifferentTokens(): void
    {
        self::assertNotSame($this->authenticate('8102')['token'], $this->authenticate('8102')['token']);
    }

    public function testAnUnknownTelegramUserIsCreatedLikeTheBotDoesAndAKnownOneIsReused(): void
    {
        $this->authenticate('8103', 'Nima');

        $account = $this->em->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => '8103']);
        self::assertInstanceOf(TelegramAccount::class, $account);
        self::assertSame('Nima Test', $account->getUser()->getName());
        self::assertSame('u8103', $account->getUsername());

        $this->authenticate('8103', 'Nima2');
        $this->em->clear();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM telegram_account WHERE telegram_id = '8103'"));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM `user`'));
        self::assertSame('Nima2', $this->em->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => '8103'])?->getFirstName());
    }

    public function testRejectedInitDataGetsNoSessionAndCreatesNoUser(): void
    {
        $valid = $this->initData('8104');
        $cases = [
            'tampered hash' => preg_replace('/hash=[0-9a-f]{4}/', 'hash=0000', $valid),
            'wrong bot token' => TelegramInitData::signed('42:another-bot', TelegramInitData::user('8104')),
            'expired' => $this->initData('8104', time() - 3700),
            'future' => $this->initData('8104', time() + 600),
            'replayed old data' => $this->initData('8104', time() - 2 * 86400),
            'missing fields' => 'user='.rawurlencode('{"id":8104}'),
        ];
        foreach ($cases as $name => $initData) {
            $this->postAuth((string) $initData);
            self::assertSame(401, $this->httpStatus(), $name);
            self::assertArrayNotHasKey('token', $this->json(), $name);
        }
        $this->client->request('POST', '/app/auth', [], [], ['CONTENT_TYPE' => 'application/json'], '{"initDataUnsafe":{"user":{"id":8104}}}');
        self::assertSame(401, $this->httpStatus(), 'initDataUnsafe is never trusted');

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mini_app_session'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM telegram_account WHERE telegram_id = '8104'"));
    }

    public function testProtectedEndpointsRefuseMissingWrongExpiredAndRevokedSessions(): void
    {
        $this->appGet('/app/api/me', null);
        self::assertSame(401, $this->httpStatus(), 'no token');

        $this->appGet('/app/api/me', ['token' => str_repeat('A', 43), 'csrf' => '']);
        self::assertSame(401, $this->httpStatus(), 'unknown token');

        $session = $this->authenticate('8105');
        $this->em->getConnection()->executeStatement('UPDATE mini_app_session SET expires_at = ?', [(new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s')]);
        $this->appGet('/app/api/me', $session);
        self::assertSame(401, $this->httpStatus(), 'expired');

        $session = $this->authenticate('8105');
        $this->appPost('/app/logout', $session);
        self::assertSame(204, $this->httpStatus());
        $this->appGet('/app/api/me', $session);
        self::assertSame(401, $this->httpStatus(), 'revoked by logout');
    }

    public function testTheSessionIsBoundToTheUserAgent(): void
    {
        $session = $this->authenticate('8106');
        $this->client->setServerParameter('HTTP_USER_AGENT', 'curl/8.0');

        $this->appGet('/app/api/me', $session);

        self::assertSame(401, $this->httpStatus());
    }

    public function testStateChangingRequestsNeedTheCsrfTokenOfThisSession(): void
    {
        $session = $this->authenticate('8107');
        $other = $this->authenticate('8108');

        $this->client->request('POST', '/app/logout', [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$session['token']]);
        self::assertSame(403, $this->httpStatus(), 'no CSRF token');
        $this->appPost('/app/logout', $session, 'forged');
        self::assertSame(403, $this->httpStatus(), 'wrong CSRF token');
        $this->appPost('/app/logout', $session, $other['csrf']);
        self::assertSame(403, $this->httpStatus(), 'another session\'s CSRF token');

        $this->appGet('/app/api/me', $session);
        self::assertSame(200, $this->httpStatus(), 'the refused requests changed nothing');
    }

    public function testTheAdminIdentityIsResolvedOnEveryRequest(): void
    {
        $admin = $this->linkedAdmin('support@example.test', AdminUser::ROLE_SUPPORT, '8109');
        $session = $this->authenticate('8109');
        $this->appGet('/app/api/me', $session);
        self::assertTrue($this->json()['isAdmin'], 'linked active admin');

        // Straight in the database (the request reset the entity manager, $admin is detached): as another process would.
        $this->em->getConnection()->executeStatement('UPDATE admin_user SET is_active = 0 WHERE id = ?', [$admin->getId()]);
        $this->appGet('/app/api/me', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertFalse($this->json()['isAdmin'], 'deactivated: no admin rights, same session, at once');

        $this->appGet('/app/api/me', $this->authenticate(self::ENV_ADMIN));
        self::assertTrue($this->json()['isAdmin'], 'TELEGRAM_ADMIN_CHAT_ID fallback, as in the bot');
    }

    public function testAuthIsRateLimitedPerIp(): void
    {
        $bad = TelegramInitData::signed('42:another-bot', TelegramInitData::user('8110'));
        for ($i = 0; $i < 20; ++$i) {
            $this->postAuth($bad);
            self::assertSame(401, $this->httpStatus());
        }
        $this->postAuth($this->initData('8110'));
        self::assertSame(429, $this->httpStatus(), 'even valid data once the address is over the limit');
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testAuthIsRateLimitedPerTelegramUser(): void
    {
        $telegramId = (string) random_int(10_000_000, 99_999_999);
        for ($i = 0; $i < 10; ++$i) {
            $this->client->setServerParameter('REMOTE_ADDR', '10.89.'.random_int(0, 255).'.'.random_int(1, 254));
            $this->postAuth($this->initData($telegramId));
            self::assertSame(200, $this->httpStatus());
        }
        $this->client->setServerParameter('REMOTE_ADDR', '10.90.'.random_int(0, 255).'.'.random_int(1, 254));
        $this->postAuth($this->initData($telegramId));
        self::assertSame(429, $this->httpStatus());
    }

    public function testTheEntryPageOutsideTelegramShowsOnlyAnOpenFromTelegramNotice(): void
    {
        $this->createActiveService($this->createUser('8111'));
        $html = $this->appGet('/app', null);
        $headers = $this->client->getResponse()->headers;

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('lang="fa" dir="rtl"', $html);
        self::assertStringContainsString('data-outside-telegram', $html);
        self::assertStringContainsString('<script src="https://telegram.org/js/telegram-web-app.js', $html);
        self::assertStringNotContainsString('8111', $html, 'no data');
        self::assertSame([], $headers->getCookies());
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertStringContainsString("script-src 'self' https://telegram.org", (string) $headers->get('Content-Security-Policy'));
        self::assertStringContainsString("font-src 'self';", (string) $headers->get('Content-Security-Policy'), 'the self-hosted Vazirmatn');
        self::assertStringContainsString('<link rel="preload" href="/miniapp/fonts/Vazirmatn-wght.woff2', $html);
        self::assertDoesNotMatchRegularExpression('#<(script|link|img)[^>]+(src|href)="(https?:)?//(?!telegram\.org/)#i', $html, 'no other external assets');
    }
}
