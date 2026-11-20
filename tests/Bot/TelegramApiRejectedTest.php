<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Bot\Infrastructure\TelegramApiRejected;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * TelegramApiRejected carries Telegram's error_code and description (its message text unchanged) and tells whether the
 * bot cannot reach the recipient at all: only then is sending the same message again pointless.
 */
final class TelegramApiRejectedTest extends IntegrationTestCase
{
    /** @return iterable<string, array{?int, string, bool}> */
    public static function answers(): iterable
    {
        yield '403 bot blocked' => [403, 'Forbidden: bot was blocked by the user', true];
        yield '403 user deactivated' => [403, 'Forbidden: user is deactivated', true];
        yield "403 can't initiate" => [403, "Forbidden: bot can't initiate conversation with a user", true];
        yield '400 chat not found' => [400, 'Bad Request: chat not found', true];
        yield '400 user not found' => [400, 'Bad Request: user not found', true];
        yield '400 PEER_ID_INVALID' => [400, 'Bad Request: PEER_ID_INVALID', true];
        yield '400 peer_id_invalid, other case' => [400, 'Bad Request: peer_id_invalid', true];
        yield '400 bad HTML' => [400, "Bad Request: can't parse entities: Unsupported start tag \"x\" at byte offset 0", false];
        yield '400 message too long' => [400, 'Bad Request: message is too long', false];
        yield '429 flood limit' => [429, 'Too Many Requests: retry after 5', false];
        yield '500' => [500, 'Internal Server Error', false];
        yield '502' => [502, 'Bad Gateway', false];
        yield 'no error_code, chat not found' => [null, 'chat not found', false];
    }

    #[DataProvider('answers')]
    public function testWhetherTheRecipientIsUnreachable(?int $code, string $description, bool $unreachable): void
    {
        self::assertSame($unreachable, (new TelegramApiRejected('x', $code, $description))->isRecipientUnreachable());
    }

    public function testTheClientSetsCodeAndDescriptionAndKeepsTheMessage(): void
    {
        $this->http->onJson('/sendMessage', ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400);

        try {
            self::getContainer()->get(TelegramApiClient::class)->sendMessageStrict('7712345602', 'hi');
            self::fail('TelegramApiRejected expected');
        } catch (TelegramApiRejected $e) {
            self::assertSame('Telegram API returned ok=false on method "sendMessage": Bad Request: chat not found', $e->getMessage());
            self::assertSame(400, $e->errorCode);
            self::assertSame('Bad Request: chat not found', $e->description);
            self::assertTrue($e->isRecipientUnreachable());
        }
    }

    public function testAnAnswerWithoutErrorCodeIsNotUnreachable(): void
    {
        $this->http->onJson('/sendMessage', ['ok' => false, 'description' => 'Forbidden: bot was blocked by the user']);

        try {
            self::getContainer()->get(TelegramApiClient::class)->sendMessageStrict('7712345602', 'hi');
            self::fail('TelegramApiRejected expected');
        } catch (TelegramApiRejected $e) {
            self::assertNull($e->errorCode);
            self::assertFalse($e->isRecipientUnreachable());
        }
    }
}
