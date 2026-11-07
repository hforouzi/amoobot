<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Guide;

use App\MiniApp\Application\MiniAppLinks;
use App\MiniApp\Guide\GuideLinks;
use App\Reseller\Application\ResellerSettings;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\TelegramInitData;
use App\Wallet\Application\WalletSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * The entry points into the guide (G-4): t.me/<bot>?startapp=guide_<slug> (Mini App sign-in start_param), /start
 * guide_<slug> in the bot and /app?guide=<slug>. Only a fixed topic slug whose topic is shown now is taken; anything
 * else (unknown, hidden, odd characters, another case, too long) is ignored silently: the usual home in the app, a
 * plain /start in the bot, and the value never comes back. The payloads that existed before (claim links, a plain
 * start, malformed and unknown ones) behave exactly as before, with the guide handler on or off.
 */
final class GuideDeepLinkEntryTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use ClaimFixtures;
    use MiniAppTestTrait;

    private const CLAIMER = '8701';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
        $this->startMiniAppClient();
        $this->createUser(self::CLAIMER);
        // The shop's public HTTPS address: the bot (no request) and the app's requests both use it, as in production,
        // where the Mini App is served from the same address its buttons open.
        self::getContainer()->get(RouterInterface::class)->getContext()->setScheme('https')->setHost('shop.example.com');
        $this->client->setServerParameter('HTTPS', 'on');
        $this->client->setServerParameter('HTTP_HOST', 'shop.example.com');
    }

    protected function tearDown(): void
    {
        // The Mini App switch is not purged between tests (ClaimFixtures): leave it off.
        $this->setting(MiniAppLinks::ENABLED_SETTING, 'false');
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'sales.%'");
        $this->tearDownClaims();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function topics(): iterable
    {
        foreach (['start', 'connect', 'pay', 'troubleshoot', 'faq'] as $slug) {
            yield $slug => [$slug];
        }
    }

    #[DataProvider('topics')]
    public function testStartAppOpensTheHelpSectionAtThatTopic(string $slug): void
    {
        $this->miniAppOn();

        $start = $this->startTarget('guide_'.$slug);

        self::assertSame('/app/view/help?topic='.$slug, $start);
        $page = new Crawler($this->appGet((string) $start, $this->session));
        self::assertSame(200, $this->httpStatus());
        self::assertCount(1, $page->filter('details[data-guide-topic="'.$slug.'"][open]'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignoredPayloads(): iterable
    {
        yield 'unknown topic' => ['guide_nothing'];
        yield 'upper case' => ['guide_Connect'];
        yield 'all upper case' => ['GUIDE_CONNECT'];
        yield 'dash' => ['guide_connect-x'];
        yield 'digits' => ['guide_connect1'];
        yield 'two underscores' => ['guide__connect'];
        yield 'trailing newline' => ["guide_connect\n"];
        yield 'trailing space' => ['guide_connect '];
        yield 'leading space' => [' guide_connect'];
        yield 'query' => ['guide_connect?x=1'];
        yield 'path' => ['guide_../admin'];
        yield 'markup' => ['guide_<b>x</b>'];
        yield 'Persian' => ['guide_راهنما'];
        yield 'oversized' => ['guide_'.str_repeat('a', 58)];
        yield 'over 64 characters' => ['guide_connect'.str_repeat('a', 60)];
        yield 'no slug' => ['guide_'];
        yield 'prefix only' => ['guide'];
    }

    #[DataProvider('ignoredPayloads')]
    public function testAnyOtherGuidePayloadOpensTheUsualHomeAndIsNotEchoed(string $payload): void
    {
        $this->miniAppOn();
        $logger = self::getContainer()->get(RecordingLogger::class);
        $before = count($logger->records);

        self::assertNull($this->startTarget($payload));
        $body = (string) $this->client->getResponse()->getContent();
        $slug = substr($payload, 6);
        if (strlen($slug) > 3) {
            self::assertStringNotContainsString($slug, $body);
            // The app's own records (Symfony's debug records name its classes, e.g. "...Connect...").
            $logged = array_filter(array_slice($logger->records, $before), static fn (array $r): bool => 'debug' !== $r['level']);
            self::assertStringNotContainsString($slug, (string) json_encode($logged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'never logged');
        }
    }

    public function testAHiddenTopicIsNotOpened(): void
    {
        $this->miniAppOn();
        $this->setting(WalletSettings::ENABLED, 'false');
        $this->setting('sales.renewals_enabled', 'false');

        self::assertNull($this->startTarget('guide_wallet'), 'wallet off');
        self::assertNull($this->startTarget('guide_renew'), 'renewals closed');

        $this->setting(WalletSettings::ENABLED, 'true');
        self::assertSame('/app/view/help?topic=wallet', $this->startTarget('guide_wallet'));
    }

    public function testWithoutTheMiniAppTheGuidePayloadIsNotTaken(): void
    {
        self::assertNull($this->startTarget('guide_connect'));

        $this->resetHttp();
        $this->sendText(self::CLAIMER, '/start guide_connect');
        self::assertSame([$this->botText('bot.welcome')], $this->http->telegramTexts(), 'as any payload nobody takes: a plain /start');
    }

    public function testStartInTheBotOffersTheAppAtThatTopic(): void
    {
        $this->miniAppOn();
        $this->resetHttp();

        $this->sendText(self::CLAIMER, '/start guide_connect');

        self::assertSame([$this->botText('miniapp.open')], $this->http->telegramTexts());
        self::assertSame(['https://shop.example.com/app?guide=connect'], $this->webAppUrls());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignoredStarts(): iterable
    {
        yield 'unknown topic' => ['/start guide_nothing'];
        yield 'upper case' => ['/start guide_CONNECT'];
        yield 'hidden topic' => ['/start guide_wallet'];
        yield 'odd characters' => ['/start guide_conn-ect'];
        yield 'oversized' => ['/start guide_'.str_repeat('z', 58)];
    }

    #[DataProvider('ignoredStarts')]
    public function testAnyOtherGuideStartGetsTheUsualAnswer(string $text): void
    {
        $this->miniAppOn();
        $this->setting(WalletSettings::ENABLED, 'false');
        $this->resetHttp();

        $this->sendText(self::CLAIMER, $text);

        self::assertSame([$this->botText('bot.welcome')], $this->http->telegramTexts());
        self::assertSame([], $this->webAppUrls());
        $slug = substr($text, 13);
        foreach ($this->http->requests() as $request) {
            self::assertStringNotContainsString($slug, (string) json_encode($this->requestBody($request), JSON_UNESCAPED_UNICODE));
        }
    }

    public function testTheEntryPageKnowsTheTopicSlugsAndNeverEchoesTheQuery(): void
    {
        $page = new Crawler($this->appGet('/app?guide=connect', null));
        self::assertSame(200, $this->httpStatus());
        self::assertSame(implode(' ', GuideLinks::topicSlugs()), $page->filter('body')->attr('data-guide-topics'));

        foreach (['<script>alert(1)</script>', 'xX_payload_Xx', '"><b>'] as $odd) {
            $html = $this->appGet('/app?guide='.rawurlencode($odd), null);
            self::assertSame(200, $this->httpStatus());
            self::assertStringNotContainsString($odd, $html);
            self::assertStringNotContainsString(htmlspecialchars($odd, ENT_QUOTES), $html);
        }
    }

    public function testTheBotsGuideLinkIsAMiniAppUrlOnlyForATopicSlug(): void
    {
        $links = self::getContainer()->get(MiniAppLinks::class);
        self::assertNull($links->forGuide('connect'), 'the Mini App is off');

        $this->miniAppOn();
        self::assertSame('https://shop.example.com/app?guide=connect', $links->forGuide('connect'));
    }

    // Regression: the payloads that existed before G-4, with the guide handler switched on (the Mini App on).

    public function testRegressionAPlainStartIsTheWelcome(): void
    {
        $this->resetHttp();
        $this->sendText(self::CLAIMER, '/start');
        $off = $this->http->telegramTexts();

        $this->miniAppOn();
        $this->resetHttp();
        $this->sendText(self::CLAIMER, '/start');

        self::assertSame($off, $this->http->telegramTexts());
        self::assertSame([$this->botText('bot.welcome')], array_slice($off, 0, 1));
        self::assertNull($this->startTarget(null));
    }

    public function testRegressionAClaimLinkStillShowsThePreviewInTheBotAndTheClaimScreenInTheApp(): void
    {
        $this->miniAppOn();
        $token = $this->makeLink()->token;
        $this->resetHttp();

        $this->sendText(self::CLAIMER, '/start claim_'.$token);
        self::assertNotSame([], array_filter($this->callbacks(), static fn (string $d): bool => str_starts_with($d, 'cl_ok:')), 'the claim preview');
        self::assertTrue($this->sentTextContains('#'.$this->sold->getId()));

        $start = (string) $this->startTarget('claim_'.$token);
        self::assertMatchesRegularExpression('#^/app/view/claim/\d+/[A-Za-z0-9_-]+$#D', $start);
        self::assertStringContainsString('data-claim-accept', $this->appGet($start, $this->session));
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold), 'opening changes nothing');
    }

    public function testRegressionClaimLinksSwitchedOffAreNotRouted(): void
    {
        $this->miniAppOn();
        $token = $this->makeLink()->token;
        $this->setting(ResellerSettings::CLAIMS_ENABLED, 'false');
        $this->resetHttp();

        $this->sendText(self::CLAIMER, '/start claim_'.$token);
        self::assertSame([$this->botText('bot.welcome')], $this->http->telegramTexts());
        self::assertNull($this->startTarget('claim_'.$token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherPayloads(): iterable
    {
        yield 'unknown prefix' => ['zzz_abc'];
        yield 'referral (not implemented)' => ['ref_ABC123'];
        yield 'malformed claim' => ['claim_ab+c'];
        yield 'upper-case claim prefix' => ['Claim_abc'];
        yield 'no prefix' => ['_abc'];
    }

    #[DataProvider('otherPayloads')]
    public function testRegressionUnknownAndMalformedPayloadsAreAPlainStart(string $payload): void
    {
        $this->miniAppOn();
        $this->resetHttp();

        $this->sendText(self::CLAIMER, '/start '.$payload);
        self::assertSame([$this->botText('bot.welcome')], $this->http->telegramTexts(), 'a plain /start');
        self::assertNull($this->startTarget($payload));
    }

    /** @var array{token: string, csrf: string}|null the session of the last startTarget() */
    private ?array $session = null;

    /**
     * Signs the claimer in to the Mini App with this start_param (or none) and returns the section the app opens first,
     * or null for the usual home.
     */
    private function startTarget(?string $startParam): ?string
    {
        $extra = null === $startParam ? [] : ['start_param' => $startParam];
        $this->postAuth(TelegramInitData::signed($this->botToken(), TelegramInitData::user(self::CLAIMER), null, $extra));
        self::assertSame(200, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        $this->session = ['token' => (string) $body['token'], 'csrf' => (string) $body['csrfToken']];
        // Sign-ins are rate limited per user; each start payload is one sign-in.
        self::getContainer()->get('cache.rate_limiter')->clear();

        return isset($body['start']) ? (string) $body['start'] : null;
    }

    private function miniAppOn(): void
    {
        $this->setting(MiniAppLinks::ENABLED_SETTING, 'true');
    }

    /**
     * @return list<string>
     */
    private function webAppUrls(): array
    {
        $urls = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $markup = $this->requestBody($request)['reply_markup'] ?? null;
            $markup = is_string($markup) ? json_decode($markup, true) : $markup;
            foreach (is_array($markup) ? $markup['inline_keyboard'] ?? [] : [] as $row) {
                foreach ($row as $button) {
                    if (isset($button['web_app']['url'])) {
                        $urls[] = (string) $button['web_app']['url'];
                    }
                }
            }
        }

        return $urls;
    }

    /**
     * @return list<string>
     */
    private function callbacks(): array
    {
        return $this->sentCallbacks();
    }
}
