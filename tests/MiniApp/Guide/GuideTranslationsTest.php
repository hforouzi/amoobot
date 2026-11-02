<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\MiniApp\Guide\GuideState;
use App\MiniApp\Guide\GuideTopic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The shipped guide texts (translations/bot.{fa,en}.yaml, guide.<topic>.title / .body) and the Help page's own strings
 * (miniapp domain, help.*): both languages have the same keys, every topic has a title and a body, and both languages
 * use the same feature blocks, topic links and placeholders, so a switch hides the same sentences in each. The texts
 * hold no link, token or card number; "connect" names no app (G-3: the apps come from the Apps admin).
 */
final class GuideTranslationsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function domains(): iterable
    {
        yield 'guide texts (bot)' => ['bot', '/^guide\./'];
        yield 'help page (miniapp)' => ['miniapp', '/^help\./'];
    }

    #[DataProvider('domains')]
    public function testPersianAndEnglishHaveTheSameKeys(string $domain, string $pattern): void
    {
        $fa = array_keys(self::texts($domain, 'fa', $pattern));
        $en = array_keys(self::texts($domain, 'en', $pattern));
        sort($fa);
        sort($en);

        self::assertNotSame([], $fa);
        self::assertSame($fa, $en);
    }

    public function testEveryTopicHasATitleAndABodyInBothLanguages(): void
    {
        foreach (['fa', 'en'] as $locale) {
            $texts = self::texts('bot', $locale, '/^guide\./');
            foreach (GuideTopic::cases() as $topic) {
                self::assertNotSame('', trim($texts[$topic->titleKey()] ?? ''), $locale.' '.$topic->titleKey());
                self::assertNotSame('', trim($texts[$topic->bodyKey()] ?? ''), $locale.' '.$topic->bodyKey());
            }
            self::assertCount(2 * count(GuideTopic::cases()), $texts, 'no guide key without a topic');
        }
    }

    public function testBothLanguagesUseTheSameBlocksLinksAndPlaceholders(): void
    {
        $fa = self::texts('bot', 'fa', '/^guide\..*\.body$/');
        $en = self::texts('bot', 'en', '/^guide\..*\.body$/');
        $slugs = array_map(static fn (GuideTopic $t): string => $t->value, GuideTopic::cases());

        foreach ($en as $key => $body) {
            self::assertSame(self::structure($body), self::structure($fa[$key]), $key);
            preg_match_all('/^\s*:::\s*if\s+(\S+)\s*$/m', $body, $flags);
            foreach ($flags[1] as $flag) {
                self::assertContains($flag, GuideState::FLAGS, $key);
            }
            preg_match_all('/\]\(#([^)]*)\)/', $body, $links);
            foreach ($links[1] as $slug) {
                self::assertContains($slug, $slugs, $key);
            }
            self::assertSame(preg_match_all('/^\s*:::\s*if\b/m', $body), preg_match_all('/^\s*:::\s*$/m', $body), 'balanced blocks in '.$key);
        }
    }

    public function testTheTextsHoldNoLinkOrSecret(): void
    {
        foreach (['fa', 'en'] as $locale) {
            foreach (self::texts('bot', $locale, '/^guide\./') as $key => $text) {
                self::assertDoesNotMatchRegularExpression('#[a-z][a-z0-9+.-]*://|www\.|t\.me/|telegram\.me|play\.google|apps\.apple|\.(com|net|org|io|app)\b|@[A-Za-z0-9_]{4,}#i', $text, $key);
                self::assertDoesNotMatchRegularExpression('/\]\((?!#)/', $text, 'only links to other topics in '.$key);
                self::assertDoesNotMatchRegularExpression('/\d{4}[- ]?\d{4}[- ]?\d{4}[- ]?\d{4}/', $text, 'card number in '.$key);
                self::assertDoesNotMatchRegularExpression('/\b[A-Za-z0-9_-]{24,}\b|\b\d{8,}:[A-Za-z0-9_-]{30,}/', $text, 'token-like string in '.$key);
            }
        }
    }

    /**
     * G-3: the "connect" topic names no app and no store: the apps the shop recommends come from the Apps admin
     * ({{ apps }}), and without any the generic text (miniapp help.apps.*) names none either. "start" and
     * "troubleshoot" still say Happ (G-1b owner decision, unchanged). No other app is named anywhere.
     */
    public function testConnectNamesNoAppAndTheOtherTextsOnlyHapp(): void
    {
        foreach (['fa', 'en'] as $locale) {
            $texts = self::texts('bot', $locale, '/^guide\./');
            $connect = $texts['guide.connect.body'];

            self::assertSame(1, preg_match_all('/^\s*\{\{ apps \}\}\s*$/m', $connect), $locale.': the apps once, on their own line');
            self::assertDoesNotMatchRegularExpression('/\bhapp\b|v2box|google play|app store/i', $connect, $locale.': no app or store in connect');
            foreach (['guide.start.body', 'guide.troubleshoot.body'] as $key) {
                self::assertStringContainsString('Happ', $texts[$key], $locale.' '.$key);
            }
            foreach ($texts as $key => $text) {
                self::assertDoesNotMatchRegularExpression('/v2box|v2rayng|hiddify|streisand|nekobox|shadowrocket|foxray|karing|sing-?box|clash/i', $text, 'no other app named in '.$key);
                self::assertDoesNotMatchRegularExpression('/google play|app store/i', $text, 'no store named in '.$key);
            }
            foreach (self::texts('miniapp', $locale, '/^help\.apps\./') as $key => $text) {
                self::assertDoesNotMatchRegularExpression('/\bhapp\b|v2box|v2ray|hiddify|nekobox|sing-?box|streisand|shadowrocket|foxray|clash/i', $text, 'no app named in '.$key);
            }
        }
    }

    /** guide.connect: four short steps, the VPN permission prompt, and the warning about the link. */
    public function testTheConnectStepsAreShortAndKeepTheWarning(): void
    {
        $en = self::texts('bot', 'en', '/^guide\.connect\.body$/')['guide.connect.body'];
        $fa = self::texts('bot', 'fa', '/^guide\.connect\.body$/')['guide.connect.body'];

        foreach (['en' => $en, 'fa' => $fa] as $locale => $body) {
            self::assertSame(4, preg_match_all('/^## /m', $body), $locale.': four steps');
            self::assertStringContainsString('(**+**)', $body, $locale);
            self::assertStringContainsString('**Allow**', $body, $locale);
            foreach (explode("\n", $body) as $line) {
                self::assertLessThanOrEqual(160, mb_strlen($line), $locale.': short lines: '.$line);
            }
        }
        foreach (['Install an app', 'My services', 'subscription page', 'QR code', 'Update (refresh) the subscription', 'Pick any server', 'VPN configuration'] as $step) {
            self::assertStringContainsString($step, $en);
        }
        self::assertStringContainsString('**Never give your subscription link to anyone, not even to support.**', $en);
        self::assertStringContainsString('**لینک اشتراکتان را به هیچ‌کس ندهید، حتی به پشتیبانی.**', $fa);
        foreach (['v2ray', 'vless', 'vmess', 'configs?', 'protocol', 'subscription url', 'import', 'deep link'] as $jargon) {
            self::assertDoesNotMatchRegularExpression('/'.$jargon.'/i', $en, 'no jargon: '.$jargon);
        }
    }

    public function testTheTroubleshootingStepsAreInTheAgreedOrder(): void
    {
        $steps = ['active, not expired', 'Update (refresh) the subscription in Happ', 'another server in Happ', 'date and time', 'Wi-Fi and mobile data', 'Remove the service from Happ', 'Contact support'];
        $body = self::texts('bot', 'en', '/^guide\.troubleshoot\.body$/')['guide.troubleshoot.body'];
        $positions = array_map(static fn (string $step): int|false => strpos($body, $step), $steps);

        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
        self::assertStringContainsString('Never send your subscription link to anyone, not even to support.', $body);
    }

    /** The order of blocks, links and placeholders of a body: the same in both languages. */
    private static function structure(string $body): string
    {
        preg_match_all('/^\s*:::.*$|\]\(#[a-z_]+\)|\{\{\s*[a-z_.]+\s*\}\}|^\s*##\s/m', $body, $m);

        return implode(' ', array_map('trim', $m[0]));
    }

    /**
     * @return array<string, string>
     */
    private static function texts(string $domain, string $locale, string $pattern): array
    {
        $all = Yaml::parseFile(dirname(__DIR__, 3).'/translations/'.$domain.'.'.$locale.'.yaml');

        return array_filter($all, static fn (string $key): bool => 1 === preg_match($pattern, $key), ARRAY_FILTER_USE_KEY);
    }
}
