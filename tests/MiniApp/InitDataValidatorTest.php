<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\MiniApp\Auth\InitDataRejected;
use App\MiniApp\Auth\InitDataValidator;
use App\Tests\Support\TelegramInitData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Telegram Mini App initData validation, exactly per Telegram's spec: HMAC-SHA256 over the sorted data-check-string
 * with key HMAC_SHA256("WebAppData", bot_token), constant-time compare, auth_date fresh, only the configured bot.
 */
final class InitDataValidatorTest extends TestCase
{
    private const TOKEN = '123456:TEST-token-for-unit-tests';
    private const NOW = 1_800_000_000;

    public function testValidInitDataIsAcceptedAndExposesTheVerifiedUser(): void
    {
        $initData = TelegramInitData::signed(self::TOKEN, TelegramInitData::user('7001', 'سارا'), self::NOW - 10, ['query_id' => 'AAH1', 'start_param' => 'svc-5']);

        $data = $this->validator()->validate($initData);

        self::assertSame('7001', $data->telegramUserId());
        self::assertSame('سارا', $data->user['first_name']);
        self::assertSame(self::NOW - 10, $data->authDate);
        self::assertSame('svc-5', $data->startParam);
    }

    public function testTheSignatureFieldIsPartOfTheHmacCheckString(): void
    {
        // Newer clients also send "signature" (Ed25519, for third parties); for the bot-token HMAC only "hash" is left out.
        $initData = TelegramInitData::signed(self::TOKEN, TelegramInitData::user('7002'), self::NOW, ['signature' => 'c2lnbmF0dXJl']);

        self::assertSame('7002', $this->validator()->validate($initData)->telegramUserId());
        $this->assertRejected(str_replace('signature=c2lnbmF0dXJl', 'signature=b3RoZXI', $initData), InitDataRejected::BAD_HASH);
    }

    public function testATamperedFieldOrHashIsRejected(): void
    {
        $initData = TelegramInitData::signed(self::TOKEN, TelegramInitData::user('7003'), self::NOW);

        // Another user id with the original hash.
        $this->assertRejected(str_replace(rawurlencode('"id":7003'), rawurlencode('"id":7004'), $initData), InitDataRejected::BAD_HASH);
        // One flipped hash character.
        $this->assertRejected(preg_replace_callback('/hash=([0-9a-f])/', static fn (array $m): string => 'hash='.('0' === $m[1] ? '1' : '0'), $initData), InitDataRejected::BAD_HASH);
        // A field added after signing.
        $this->assertRejected($initData.'&start_param=admin', InitDataRejected::BAD_HASH);
    }

    public function testDataSignedWithAnotherBotTokenIsRejected(): void
    {
        $this->assertRejected(TelegramInitData::signed('999999:another-bot', TelegramInitData::user('7005'), self::NOW), InitDataRejected::BAD_HASH);
    }

    public function testOnlyConfiguredBotTokensAreAccepted(): void
    {
        $other = '555:second-configured-bot';
        $validator = new InitDataValidator([self::TOKEN, '', $other], new MockClock('@'.self::NOW));

        self::assertSame('7006', $validator->validate(TelegramInitData::signed($other, TelegramInitData::user('7006'), self::NOW))->telegramUserId());

        $this->expectExceptionObject(new InitDataRejected(InitDataRejected::NOT_CONFIGURED));
        (new InitDataValidator(['', ' '], new MockClock('@'.self::NOW)))->validate(TelegramInitData::signed('', TelegramInitData::user('7006'), self::NOW));
    }

    public function testExpiredAuthDateIsRejectedAndTheMaxAgeIsConfigurable(): void
    {
        $old = TelegramInitData::signed(self::TOKEN, TelegramInitData::user('7007'), self::NOW - 3601);
        $this->assertRejected($old, InitDataRejected::EXPIRED);

        $fresh = TelegramInitData::signed(self::TOKEN, TelegramInitData::user('7007'), self::NOW - 3600);
        self::assertSame('7007', $this->validator()->validate($fresh)->telegramUserId(), 'exactly one hour is still accepted');

        $strict = new InitDataValidator([self::TOKEN], new MockClock('@'.self::NOW), 300);
        $this->expectExceptionObject(new InitDataRejected(InitDataRejected::EXPIRED));
        $strict->validate(TelegramInitData::signed(self::TOKEN, TelegramInitData::user('7007'), self::NOW - 301));
    }

    public function testReplayedOldInitDataIsRejectedEvenThoughItsHashIsValid(): void
    {
        // Captured yesterday, genuinely signed by Telegram: the HMAC still matches, only the age stops it.
        $captured = TelegramInitData::signed(self::TOKEN, TelegramInitData::user('7008'), self::NOW - 86400);

        $this->assertRejected($captured, InitDataRejected::EXPIRED);
    }

    public function testAuthDateInTheFutureBeyondTheSkewIsRejected(): void
    {
        self::assertSame('7009', $this->validator()->validate(TelegramInitData::signed(self::TOKEN, TelegramInitData::user('7009'), self::NOW + 60))->telegramUserId(), 'small clock skew');
        $this->assertRejected(TelegramInitData::signed(self::TOKEN, TelegramInitData::user('7009'), self::NOW + 61), InitDataRejected::FUTURE);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformed(): iterable
    {
        $user = json_encode(TelegramInitData::user('7010'), JSON_THROW_ON_ERROR);

        yield 'empty' => ['', InitDataRejected::MALFORMED];
        yield 'missing hash' => [http_build_query(['auth_date' => (string) self::NOW, 'user' => $user]), InitDataRejected::MISSING_HASH];
        yield 'empty hash' => [http_build_query(['auth_date' => (string) self::NOW, 'user' => $user, 'hash' => '']), InitDataRejected::MISSING_HASH];
        yield 'hash not hex' => [http_build_query(['auth_date' => (string) self::NOW, 'user' => $user, 'hash' => str_repeat('z', 64)]), InitDataRejected::MISSING_HASH];
        yield 'duplicate field' => ['auth_date=1&auth_date=2&hash='.str_repeat('a', 64), InitDataRejected::MALFORMED];
        yield 'too long' => [str_repeat('a', 9000), InitDataRejected::MALFORMED];
    }

    #[DataProvider('malformed')]
    public function testMalformedInputIsRejected(string $initData, string $reason): void
    {
        $this->assertRejected($initData, $reason);
    }

    public function testSignedButIncompleteDataIsRejected(): void
    {
        $withoutAuthDate = ['user' => json_encode(TelegramInitData::user('7011'), JSON_THROW_ON_ERROR)];
        $this->assertRejected($this->sign($withoutAuthDate), InitDataRejected::MISSING_AUTH_DATE);
        $this->assertRejected($this->sign(['auth_date' => 'yesterday'] + $withoutAuthDate), InitDataRejected::MISSING_AUTH_DATE);

        $this->assertRejected($this->sign(['auth_date' => (string) self::NOW]), InitDataRejected::MISSING_USER);
        $this->assertRejected($this->sign(['auth_date' => (string) self::NOW, 'user' => 'not json']), InitDataRejected::MISSING_USER);
        $this->assertRejected($this->sign(['auth_date' => (string) self::NOW, 'user' => '{"first_name":"x"}']), InitDataRejected::MISSING_USER);
        $this->assertRejected($this->sign(['auth_date' => (string) self::NOW, 'user' => '{"id":0}']), InitDataRejected::MISSING_USER);
        $this->assertRejected($this->sign(['auth_date' => (string) self::NOW, 'user' => '{"id":"12abc"}']), InitDataRejected::MISSING_USER);
    }

    public function testTheRejectionMessageNeverContainsTheInitData(): void
    {
        $initData = TelegramInitData::signed('999999:another-bot', TelegramInitData::user('7012'), self::NOW);
        try {
            $this->validator()->validate($initData);
            self::fail('expected a rejection');
        } catch (InitDataRejected $e) {
            self::assertSame(InitDataRejected::BAD_HASH, $e->getMessage(), 'only the reason code');
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private function sign(array $fields): string
    {
        $fields['hash'] = TelegramInitData::hash(self::TOKEN, $fields);

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }

    private function assertRejected(string $initData, string $reason): void
    {
        try {
            $this->validator()->validate($initData);
        } catch (InitDataRejected $e) {
            self::assertSame($reason, $e->reason);

            return;
        }
        self::fail('initData was accepted, expected '.$reason);
    }

    private function validator(): InitDataValidator
    {
        return new InitDataValidator([self::TOKEN], new MockClock('@'.self::NOW));
    }
}
