<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\MiniApp\Guide\GuideTextRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What an admin may save as a guide text (GuideTextRules, G-2): one test per rule, and the shipped defaults pass.
 */
final class GuideTextRulesTest extends TestCase
{
    public function testTheGuideKeys(): void
    {
        self::assertTrue(GuideTextRules::isGuideKey('guide.connect.body'));
        self::assertTrue(GuideTextRules::isGuideKey('guide.faq.title'));
        self::assertFalse(GuideTextRules::isGuideKey('guide.nope.body'), 'unknown topic');
        self::assertFalse(GuideTextRules::isGuideKey('guide.connect.text'));
        self::assertFalse(GuideTextRules::isGuideKey('bot.welcome'));
        self::assertFalse(GuideTextRules::isGuideKey("guide.connect.body\n"));
        self::assertCount(24, GuideTextRules::keys());
        self::assertSame(['message' => 'guide_text.key', 'parameters' => []], GuideTextRules::problems('guide.nope.body', 'x')[0]);
    }

    public function testAValidTextHasNoProblem(): void
    {
        $body = "## Install\n1. Open **Happ**.\n2. See [paying](#pay) or [the site](https://example.com/help?a=1).\n\n{{ payment_methods }}\n"
            ."::: if wallet\n- Wallet at {{ bot.brandName }}\n::: if card_to_card\n- Card\n:::\n:::\nZWNJ: می‌توانید\r\nEnd.";
        self::assertSame([], GuideTextRules::problems('guide.pay.body', $body));
        self::assertSame([], GuideTextRules::problems('guide.pay.title', 'Pay at {{bot.brandName}}'));
        self::assertSame([], GuideTextRules::problems('guide.faq.body', ''), 'an empty body hides the topic');
    }

    public function testTheShippedDefaultsPass(): void
    {
        foreach (['fa', 'en'] as $locale) {
            $texts = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 3).'/translations/bot.'.$locale.'.yaml');
            foreach (GuideTextRules::keys() as $key) {
                $text = $this->find($texts, $key);
                self::assertIsString($text, $locale.' '.$key);
                self::assertSame([], GuideTextRules::problems($key, $text), $locale.' '.$key);
            }
        }
    }

    public function testLength(): void
    {
        self::assertSame([], GuideTextRules::problems('guide.faq.body', str_repeat('ب', 4000)));
        self::assertSame(['guide_text.too_long'], $this->messages('guide.faq.body', str_repeat('ب', 4001)));
        self::assertSame(['%max%' => '4000', '%length%' => '4001'], GuideTextRules::problems('guide.faq.body', str_repeat('a', 4001))[0]['parameters']);
        self::assertSame([], GuideTextRules::problems('guide.faq.title', str_repeat('a', 80)));
        self::assertSame(['guide_text.too_long'], $this->messages('guide.faq.title', str_repeat('a', 81)));
        self::assertSame(['guide_text.title_one_line'], $this->messages('guide.faq.title', "Two\nlines"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badCharacters(): iterable
    {
        yield 'right-to-left override' => ["Pay \u{202E}here"];
        yield 'left-to-right embedding' => ["\u{202A}x"];
        yield 'isolate' => ["\u{2067}x\u{2069}"];
        yield 'zero-width space' => ["a\u{200B}b"];
        yield 'byte order mark' => ["\u{FEFF}text"];
        yield 'private use' => ["\u{E000}"];
        yield 'line separator' => ["a\u{2028}b"];
    }

    #[DataProvider('badCharacters')]
    public function testInvisibleAndBidiCharactersAreRefused(string $text): void
    {
        self::assertContains('guide_text.characters', $this->messages('guide.faq.body', $text));
    }

    public function testControlCharactersAreRefused(): void
    {
        self::assertContains('guide_text.control', $this->messages('guide.faq.body', "a\tb"));
        self::assertContains('guide_text.control', $this->messages('guide.faq.body', "a\x1B[31mb"));
        self::assertContains('guide_text.control', $this->messages('guide.faq.body', "a\x00b"));
        self::assertSame(['guide_text.characters'], $this->messages('guide.faq.body', "bad \xC3\x28 utf8"));
    }

    public function testPlaceholders(): void
    {
        $problems = GuideTextRules::problems('guide.faq.body', 'Hi {{ user.name }} and {{ order.trackingCode }}');
        self::assertSame(['guide_text.placeholder', 'guide_text.placeholder'], array_column($problems, 'message'));
        self::assertSame('user.name', $problems[0]['parameters']['%name%']);
        self::assertStringContainsString('{{ payment_methods }}', $problems[0]['parameters']['%allowed%']);
        self::assertStringContainsString('{{ bot.brandName }}', $problems[0]['parameters']['%allowed%']);

        self::assertSame(['guide_text.placeholder'], $this->messages('guide.pay.title', '{{ payment_methods }}'), 'not in a title');
        self::assertSame(['guide_text.placeholder_own_line'], $this->messages('guide.pay.body', 'Methods: {{ payment_methods }}'));
        self::assertSame(['guide_text.placeholder_unclosed'], $this->messages('guide.pay.body', 'Hello {{ bot.brandName'));
        self::assertSame(['guide_text.placeholder_unclosed'], $this->messages('guide.pay.body', 'stray }} here'));
    }

    public function testFeatureBlocks(): void
    {
        $problems = GuideTextRules::problems('guide.faq.body', "a\n::: if lottery\nb\n:::");
        self::assertSame(['guide_text.block_feature'], array_column($problems, 'message'));
        self::assertSame(['%line%' => '2', '%name%' => 'lottery', '%allowed%' => implode(', ', \App\MiniApp\Guide\GuideState::FLAGS)], $problems[0]['parameters']);

        self::assertSame(['guide_text.block_unclosed'], $this->messages('guide.faq.body', "::: if wallet\nb"));
        self::assertSame(['guide_text.block_unclosed'], $this->messages('guide.faq.body', "::: if wallet\n::: if trial\n:::\nb"));
        self::assertSame(['guide_text.block_stray_close'], $this->messages('guide.faq.body', "b\n:::"));
        self::assertSame(['guide_text.block_line'], $this->messages('guide.faq.body', "::: wallet\nb"));
        self::assertSame(['guide_text.block_line', 'guide_text.block_stray_close'], $this->messages('guide.faq.body', "::: if\nb\n:::"));
        self::assertSame(['guide_text.block_in_title'], $this->messages('guide.faq.title', '::: if wallet'));
    }

    public function testLinks(): void
    {
        self::assertSame(['guide_text.link_topic'], $this->messages('guide.faq.body', 'See [x](#nope)'));
        self::assertSame(['guide_text.link_target'], $this->messages('guide.faq.body', '[x](javascript:alert(1))'), 'the parentheses end the target early; still refused');
        self::assertSame(['guide_text.link_target'], $this->messages('guide.faq.body', '[x](javascript:alert)'));
        self::assertSame(['guide_text.link_target'], $this->messages('guide.faq.body', '[x](http://example.com)'));
        self::assertSame(['guide_text.link_target'], $this->messages('guide.faq.body', '[x](data:text/html,hi)'));
        self::assertSame(['guide_text.link_target'], $this->messages('guide.faq.body', '[x](https://user:pw@example.com)'));
        self::assertSame(['guide_text.link_target'], $this->messages('guide.faq.body', '[x](/app/view/help)'));
        self::assertSame([], $this->messages('guide.faq.body', '[x](https://t.me/shop)'));
    }

    /**
     * HTML is not refused (the renderer shows it as text); it is not an error to write "<b>".
     */
    public function testHtmlIsNotAnError(): void
    {
        self::assertSame([], $this->messages('guide.faq.body', '<script>alert(1)</script> <b onmouseover="x">'));
    }

    /**
     * @return list<string>
     */
    private function messages(string $key, string $text): array
    {
        return array_column(GuideTextRules::problems($key, $text), 'message');
    }

    /**
     * @param array<mixed> $texts
     */
    private function find(array $texts, string $key): mixed
    {
        if (array_key_exists($key, $texts)) {
            return $texts[$key];
        }
        $node = $texts;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }
}
