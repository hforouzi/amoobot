<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Reseller\Application\ResellerSettings;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Old users coming back (the bot's database was lost, their client still shows an old reply keyboard): a /start with a
 * payload no handler takes ("comeback" from a shared t.me link, anything malformed or too long) is a plain /start, the
 * welcome with the current main reply keyboard, and one info line counts it (the payload only when it fits Telegram's
 * start alphabet, never the value of a handled prefix such as a claim token). Any other unknown text in a private chat
 * gets the "invalid command" answer with the main keyboard, so an old button brings up the new menu.
 */
final class StartFallbackTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ClaimFixtures;

    private const VISITOR = '8702';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}> the /start text, the payload as logged
     */
    public static function unknownStarts(): iterable
    {
        yield 'comeback' => ['/start comeback', 'comeback'];
        yield 'with the bot name' => ['/start@amoo_test_bot comeback', 'comeback'];
        yield 'unknown prefix' => ['/start zzz_abc', 'zzz_abc'];
        yield 'upper-case prefix' => ['/start Claim_abc', 'Claim_abc'];
        yield '64 characters' => ['/start '.str_repeat('a', 64), str_repeat('a', 64)];
        yield 'over 64 characters' => ['/start '.str_repeat('a', 65), '<invalid>'];
        yield 'other characters' => ['/start come+back', '<invalid>'];
        yield 'two words' => ['/start come back', '<invalid>'];
        yield 'Persian' => ['/start بازگشت', '<invalid>'];
        yield 'markup' => ['/start <b>x</b>', '<invalid>'];
        yield 'trailing newline' => ["/start comeback\n", '<invalid>'];
    }

    public function testAPlainStartIsTheWelcomeWithTheMainKeyboard(): void
    {
        $this->sendText(self::VISITOR, '/start');

        self::assertSame([$this->botText('bot.welcome')], $this->http->telegramTexts());
        $this->assertMainKeyboard($this->replyMarkups()[0]);
        self::assertSame([], $this->unknownPayloadLogs(0));
    }

    #[DataProvider('unknownStarts')]
    public function testAnUnknownPayloadIsAPlainStartAndIsCounted(string $text, string $logged): void
    {
        $this->sendText(self::VISITOR, '/start');
        $plain = $this->replyMarkups();
        $logger = self::getContainer()->get(RecordingLogger::class);
        $before = count($logger->records);
        $this->resetHttp();

        $this->sendText(self::VISITOR, $text);

        self::assertSame([$this->botText('bot.welcome')], $this->http->telegramTexts());
        self::assertSame($plain, $this->replyMarkups(), 'the same main keyboard as a plain /start');
        $this->assertMainKeyboard($this->replyMarkups()[0]);
        self::assertSame([['payload' => $logged]], $this->unknownPayloadLogs($before));
    }

    public function testAPlainStartWithTheBotNameIsNotLogged(): void
    {
        $logger = self::getContainer()->get(RecordingLogger::class);
        $before = count($logger->records);

        $this->sendText(self::VISITOR, '/start@amoo_test_bot');

        self::assertSame([$this->botText('bot.welcome')], $this->http->telegramTexts());
        self::assertSame([], $this->unknownPayloadLogs($before));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function oldButtons(): iterable
    {
        yield 'an old keyboard button' => ['📊 وضعیت حساب من'];
        yield 'any text' => ['hello'];
        yield 'another command' => ['/help'];
    }

    #[DataProvider('oldButtons')]
    public function testUnknownTextGetsTheInvalidCommandAnswerWithTheMainKeyboard(string $text): void
    {
        $this->sendText(self::VISITOR, $text);

        self::assertSame([$this->botText('bot.unknown_message')], $this->http->telegramTexts());
        $this->assertMainKeyboard($this->replyMarkups()[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignoreArgs(): iterable
    {
        yield 'arguments in traces' => ['0'];
        yield 'no arguments' => ['1'];
    }

    #[DataProvider('ignoreArgs')]
    public function testAKnownPayloadIsNeverLogged(string $ignoreArgs): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', $ignoreArgs);
        try {
            $token = $this->makeLink()->token;
            $logger = self::getContainer()->get(RecordingLogger::class);
            $logs = count($logger->records);

            // Taken: the claim preview, as before.
            $this->sendText(self::VISITOR, '/start claim_'.$token);
            self::assertNotSame([], array_filter($this->sentCallbacks(), static fn (string $d): bool => str_starts_with($d, 'cl_ok:')), 'the claim preview');

            // Not taken (claim links off, a guide topic with the Mini App off, a well-formed but unknown claim token):
            // a plain /start, and still nothing of the value is logged.
            $this->setting(ResellerSettings::CLAIMS_ENABLED, 'false');
            $this->resetHttp();
            $this->sendText(self::VISITOR, '/start claim_'.$token);
            $this->sendText(self::VISITOR, '/start guide_secrettopic');
            $this->setting(ResellerSettings::CLAIMS_ENABLED, 'true');
            $this->sendText(self::VISITOR, '/start claim_'.strrev($token));
            self::assertSame([$this->botText('bot.welcome'), $this->botText('bot.welcome')], array_slice($this->http->telegramTexts(), 0, 2));

            $text = (string) json_encode(array_slice($logger->records, $logs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            foreach ([$token, strrev($token), substr($token, 0, 20), 'secrettopic'] as $secret) {
                self::assertStringNotContainsString($secret, $text);
            }
            self::assertSame([], $this->unknownPayloadLogs($logs));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    /**
     * @return list<array<mixed>> the context of every start_payload_unknown line since $from
     */
    private function unknownPayloadLogs(int $from): array
    {
        $records = array_slice(self::getContainer()->get(RecordingLogger::class)->records, $from);

        return array_values(array_map(static fn (array $r): array => $r['context'], array_filter($records, static fn (array $r): bool => 'start_payload_unknown' === $r['message'] && 'info' === $r['level'])));
    }

    /**
     * @return list<array<mixed>|null>
     */
    private function replyMarkups(): array
    {
        $markups = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            $markups[] = is_string($markup) ? json_decode($markup, true) : $markup;
        }

        return $markups;
    }

    /**
     * @param array<mixed>|null $markup
     */
    private function assertMainKeyboard(?array $markup): void
    {
        self::assertIsArray($markup);
        self::assertIsArray($markup['keyboard'] ?? null, 'a reply keyboard, which replaces the one the client has');
        $buttons = array_merge(...array_map(static fn (array $row): array => array_column($row, 'text'), $markup['keyboard']));
        self::assertContains($this->botButton('button.main.my_services'), $buttons);
        self::assertContains($this->botButton('button.main.support'), $buttons);
    }
}
