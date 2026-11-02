<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\Bot\Application\DeepLink\StartPayload;
use App\MiniApp\Guide\GuideLinks;
use App\MiniApp\Guide\GuideTopic;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The allow-list of deep-link topic slugs (GuideLinks::isTopicSlug, G-4): exactly the guide's fixed topic slugs,
 * checked as given (no trimming, no case folding); everything else is not a topic. And what a guide start payload can
 * carry at all (StartPayload: at most 64 characters, [A-Za-z0-9_-]).
 */
final class GuideLinksSlugTest extends TestCase
{
    public function testTheAllowListIsTheGuidesTopics(): void
    {
        self::assertSame(['start', 'buy', 'pay', 'wallet', 'connect', 'usage', 'renew', 'traffic', 'troubleshoot', 'claim', 'faq', 'support'], GuideLinks::topicSlugs());
        self::assertSame(array_map(static fn (GuideTopic $topic): string => $topic->value, GuideTopic::cases()), GuideLinks::topicSlugs());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function topics(): iterable
    {
        foreach (GuideLinks::topicSlugs() as $slug) {
            yield $slug => [$slug];
        }
    }

    #[DataProvider('topics')]
    public function testEveryTopicSlugIsATopic(string $slug): void
    {
        self::assertTrue(GuideLinks::isTopicSlug($slug));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notTopics(): iterable
    {
        yield 'unknown' => ['nothing'];
        yield 'empty' => [''];
        yield 'upper case' => ['CONNECT'];
        yield 'capitalised' => ['Connect'];
        yield 'leading space' => [' connect'];
        yield 'trailing space' => ['connect '];
        yield 'trailing newline' => ["connect\n"];
        yield 'NUL' => ["connect\0"];
        yield 'dash' => ['con-nect'];
        yield 'digit' => ['connect1'];
        yield 'query' => ['connect?x=1'];
        yield 'path' => ['../connect'];
        yield 'markup' => ['<connect>'];
        yield 'Persian' => ['اتصال'];
        yield 'Cyrillic look-alike' => ["\u{0441}onnect"];
        yield 'oversized' => [str_repeat('a', 33)];
        yield 'oversized topic' => ['connect'.str_repeat('_', 40)];
        yield 'not a string' => [42];
        yield 'null' => [null];
        yield 'array' => [['connect']];
    }

    #[DataProvider('notTopics')]
    public function testAnythingElseIsNotATopic(mixed $slug): void
    {
        self::assertFalse(GuideLinks::isTopicSlug($slug));
    }

    public function testAGuidePayloadIsParsedLikeEveryOther(): void
    {
        $payload = StartPayload::parse('guide_troubleshoot');
        self::assertSame(['guide', 'troubleshoot'], [$payload?->prefix, $payload?->value]);
        self::assertNull(StartPayload::parse('guide_connect '));
        self::assertNull(StartPayload::parse('guide_'.str_repeat('a', 59)), 'over 64 characters');
        self::assertNull(StartPayload::parse('Guide_connect'));
        $upper = StartPayload::parse('guide_Connect');
        self::assertNotNull($upper);
        self::assertSame('Connect', $upper->value, 'parsed, then refused by the allow-list');
        self::assertFalse(GuideLinks::isTopicSlug($upper->value));
    }
}
