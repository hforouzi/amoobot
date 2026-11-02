<?php

declare(strict_types=1);

namespace App\Tests\Bot;

use App\Branding\SupportLinkSettings;
use App\Bot\Application\TelegramKeyboardFactory;
use App\Entity\Setting;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The bot's support answer (TelegramUpdateHandler::handleSupport(), the "Support" button and the "support" callback):
 * bot.support as it always was; with a support chat set (support.url, SupportLinkSettings) an inline URL button
 * "Message support" above "Back". Without one, or with a stored value that breaks the rule, the request sent to
 * Telegram is byte for byte the one sent before support.url existed.
 */
final class SupportButtonTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private const CUSTOMER = '5301';

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [SupportLinkSettings::URL]);
        parent::tearDown();
    }

    public function testWithoutASupportChatTheAnswerIsUnchanged(): void
    {
        $body = $this->supportRequest('callback');

        // What handleSupport() sent before support.url: the text and backToMainMenu(), nothing else.
        $expected = http_build_query(['chat_id' => self::CUSTOMER, 'text' => $this->botText('bot.support'), 'reply_markup' => self::getContainer()->get(TelegramKeyboardFactory::class)->backToMainMenu()]);
        self::assertSame(['chat_id', 'text', 'reply_markup'], array_keys($this->decoded($body)));
        self::assertSame($this->decoded($expected), $this->decoded($body));
        self::assertSame([[['text' => $this->botButton('button.common.back'), 'callback_data' => 'main_menu']]], $this->markup($body));
        self::assertStringNotContainsString('t.me', $body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function noButton(): iterable
    {
        yield 'empty' => [''];
        yield 'another host (changed by hand)' => ['https://evil.example/shop_support'];
        yield 'tg scheme (changed by hand)' => ['tg://resolve?domain=shop_support'];
        yield 'query string (changed by hand)' => ['https://t.me/shop_support?start=x'];
    }

    #[DataProvider('noButton')]
    public function testAnEmptyOrBrokenSettingSendsTheSameBytes(string $stored): void
    {
        $before = $this->supportRequest('callback');
        $this->http->reset();
        $this->store($stored);

        self::assertSame($before, $this->supportRequest('callback'));
    }

    public function testWithASupportChatTheButtonOpensIt(): void
    {
        $without = $this->supportRequest('callback');
        $this->http->reset();
        $this->store('https://telegram.me/Shop_Support_1');

        foreach (['callback', 'reply'] as $via) {
            $this->http->reset();
            $body = $this->supportRequest($via);
            self::assertSame($this->decoded($without)['text'], $this->decoded($body)['text'], 'the support text is unchanged');
            self::assertSame([
                [['text' => $this->botButton('button.support.message'), 'url' => 'https://t.me/Shop_Support_1']],
                [['text' => $this->botButton('button.common.back'), 'callback_data' => 'main_menu']],
            ], $this->markup($body), $via);
        }
        self::assertSame('💬 پیام به پشتیبانی', $this->botButton('button.support.message'));
    }

    private function store(string $value): void
    {
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['keyName' => SupportLinkSettings::URL]) ?? (new Setting())->setKeyName(SupportLinkSettings::URL)->setType('text');
        $this->em->persist($setting->setValue($value));
        $this->em->flush();
        self::getContainer()->get(SupportLinkSettings::class)->reset();
    }

    /** The raw body of the one sendMessage the support answer makes. */
    private function supportRequest(string $via): string
    {
        if ('callback' === $via) {
            $this->pressButton(self::CUSTOMER, 'support');
        } else {
            $this->sendText(self::CUSTOMER, $this->botButton('button.main.support'));
        }
        $requests = $this->http->requests('/sendMessage');
        self::assertCount(1, $requests);
        $body = $requests[0]['options']['body'] ?? null;
        self::assertIsString($body);

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            parse_str($body, $decoded);
        }

        return $decoded;
    }

    /**
     * @return list<list<array<string, string>>>
     */
    private function markup(string $body): array
    {
        $markup = $this->decoded($body)['reply_markup'] ?? null;

        return (is_string($markup) ? json_decode($markup, true) : $markup)['inline_keyboard'];
    }
}
