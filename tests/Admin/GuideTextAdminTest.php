<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\Entity\BotMessageTemplate;
use App\MiniApp\Guide\MiniAppGuide;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppShopFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Mini App guide's texts in the Bot Content admin (G-2, docs/design/help-guide.md, sections 5 and 6): the overview
 * under the "Guide" filter, "Customize" with the shipped default, the save-time rules shown as form errors, the live
 * preview (CSRF, permission, escaping, direction), "Reset to default" (confirmation, CSRF, audit) and the permission
 * BOT_TEXTS_MANAGE for all of it.
 */
final class GuideTextAdminTest extends IntegrationTestCase
{
    use MiniAppShopFixtures;

    private const INDEX = '/admin/bot-texts/messages';
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShop();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM admin_audit_log');
        $connection->executeStatement('DELETE FROM admin_user');
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->loginUser($this->admin(AdminUser::ROLE_ADMIN), 'main');
        $this->client->request('GET', '/admin/locale/en');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement("DELETE FROM setting WHERE key_name LIKE 'sales.%' OR key_name = 'wallet.enabled'");
        parent::tearDown();
    }

    public function testTheGuideFilterListsEveryGuideTextInBothLanguages(): void
    {
        $this->guideRow('guide.faq.body', 'en', 'My FAQ');
        $crawler = $this->client->request('GET', self::INDEX.'?filters[category][comparison]==&filters[category][value]=guide');
        self::assertSame(200, $this->httpCode());

        $rows = $crawler->filter('[data-guide-overview] tr[data-guide-key]');
        self::assertCount(24, $rows);
        $faq = $crawler->filter('tr[data-guide-key="guide.faq.body"]');
        self::assertCount(1, $faq->filter('td[data-locale="en"] [data-status="customized"]'));
        self::assertCount(1, $faq->filter('td[data-locale="fa"] [data-status="default"]'));
        self::assertStringContainsString('key=guide.faq.body', (string) $faq->filter('td[data-locale="fa"] a[data-customize]')->attr('href'));
        self::assertStringContainsString('/edit', (string) $faq->filter('td[data-locale="en"] a[data-edit]')->attr('href'));
        // The customized row is also an ordinary row of the filtered list; other texts are not.
        self::assertStringContainsString('guide.faq.body', $crawler->filter('table.datagrid')->last()->text());

        // Without the filter: no overview; the list action leads to it.
        $crawler = $this->client->request('GET', self::INDEX);
        self::assertCount(0, $crawler->filter('[data-guide-overview]'));
        self::assertStringContainsString('filters', (string) $crawler->filter('a.action-guideTexts')->attr('href'));
    }

    public function testCustomizeStartsFromTheShippedDefaultAndSaving(): void
    {
        $crawler = $this->client->request('GET', self::INDEX.'/new?key=guide.connect.body&locale=en');
        self::assertSame(200, $this->httpCode());
        $form = $crawler->filter('form[name="BotMessageTemplate"]')->form();
        self::assertSame('guide.connect.body', $form['BotMessageTemplate[key]']->getValue());
        self::assertSame('guide', $form['BotMessageTemplate[category]']->getValue());
        self::assertStringContainsString('Install Happ', (string) $form['BotMessageTemplate[body]']->getValue());
        self::assertSame(['guide'], $this->options($crawler, 'BotMessageTemplate[parseMode]'), 'only the guide parse mode');
        self::assertCount(1, $crawler->filter('[data-guide-preview] iframe[sandbox=""][data-guide-frame]'));

        $form['BotMessageTemplate[body]'] = "## Install\nOpen **Happ** at {{ bot.brandName }}.";
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect(), (string) $this->httpCode());

        $row = $this->savedRow('guide.connect.body', 'en');
        self::assertSame('guide', $row->getParseMode());
        $this->assertAudited('bot_text.create', (string) $row->getId());
        $topic = $this->topic('connect', 'en');
        self::assertStringContainsString('<strong>Happ</strong>', (string) $topic['body']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refused(): iterable
    {
        yield 'unknown placeholder' => ['guide.faq.body', 'Hi {{ user.name }}', 'Unknown placeholder {{ user.name }}'];
        yield 'unknown feature' => ['guide.faq.body', "::: if lottery\nWin\n:::", 'unknown feature "lottery"'];
        yield 'unclosed block' => ['guide.faq.body', "::: if wallet\nWallet", 'are not closed'];
        yield 'stray close' => ['guide.faq.body', "Text\n:::", 'closes a block that was never opened'];
        yield 'malformed block line' => ['guide.faq.body', "::: wallet\nx\n:::", 'is not a block line'];
        yield 'body too long' => ['guide.faq.body', str_repeat('a', 4001), 'Too long: 4001 characters, at most 4000'];
        yield 'title too long' => ['guide.faq.title', str_repeat('a', 81), 'Too long: 81 characters, at most 80'];
        yield 'title two lines' => ['guide.faq.title', "a\nb", 'single line'];
        yield 'bidi override' => ['guide.faq.body', "Pay \u{202E}here", 'invisible or unusual characters'];
        yield 'control character' => ['guide.faq.body', "a\x1Bb", 'control characters'];
        yield 'javascript link' => ['guide.faq.body', '[x](javascript:alert(1))', 'is not allowed'];
        yield 'unknown topic link' => ['guide.faq.body', '[x](#nope)', 'unknown topic #nope'];
        yield 'payment methods inline' => ['guide.pay.body', 'Pay: {{ payment_methods }}', 'must stand alone'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testEachRuleIsAFormError(string $key, string $body, string $message): void
    {
        $crawler = $this->client->request('GET', self::INDEX.'/new?key='.$key.'&locale=en');
        $form = $crawler->filter('form[name="BotMessageTemplate"]')->form();
        $form['BotMessageTemplate[body]'] = $body;
        $crawler = $this->client->submit($form);

        self::assertSame(422, $this->httpCode());
        self::assertStringContainsString($message, $crawler->filter('form[name="BotMessageTemplate"]')->text());
        self::assertNull($this->em->getRepository(BotMessageTemplate::class)->findOneBy(['key' => $key, 'locale' => 'en']));
    }

    public function testTheGuideParseModeIsForGuideKeysOnly(): void
    {
        // A guide key saved with another parse mode, and the guide mode on another key: both refused.
        $crawler = $this->client->request('GET', self::INDEX.'/new');
        self::assertContains('guide', $this->options($crawler, 'BotMessageTemplate[parseMode]'));
        $form = $crawler->filter('form[name="BotMessageTemplate"]')->form([
            'BotMessageTemplate[key]' => 'bot.custom_note', 'BotMessageTemplate[locale]' => 'en', 'BotMessageTemplate[category]' => 'general',
            'BotMessageTemplate[title]' => 'x', 'BotMessageTemplate[body]' => 'x', 'BotMessageTemplate[parseMode]' => 'guide',
        ]);
        $crawler = $this->client->submit($form);
        self::assertSame(422, $this->httpCode());
        self::assertStringContainsString('only for the texts of the guide', $crawler->filter('form[name="BotMessageTemplate"]')->text());

        $form = $crawler->filter('form[name="BotMessageTemplate"]')->form([
            'BotMessageTemplate[key]' => 'guide.faq.body', 'BotMessageTemplate[parseMode]' => 'html',
        ]);
        $crawler = $this->client->submit($form);
        self::assertSame(422, $this->httpCode());
        self::assertStringContainsString('must use the parse mode "Guide"', $crawler->filter('form[name="BotMessageTemplate"]')->text());

        $crawler = $this->client->request('GET', self::INDEX.'/new');
        $form = $crawler->filter('form[name="BotMessageTemplate"]')->form([
            'BotMessageTemplate[key]' => 'guide.lottery.body', 'BotMessageTemplate[locale]' => 'en', 'BotMessageTemplate[category]' => 'guide',
            'BotMessageTemplate[title]' => 'x', 'BotMessageTemplate[body]' => 'x', 'BotMessageTemplate[parseMode]' => 'guide',
        ]);
        $crawler = $this->client->submit($form);
        self::assertSame(422, $this->httpCode());
        self::assertStringContainsString('This key is not a text of the guide', $crawler->filter('form[name="BotMessageTemplate"]')->text());

        // The edit page of another text does not offer it.
        $other = (new BotMessageTemplate())->setKey('bot.welcome')->setLocale('en')->setTitle('Welcome')->setBody('Hi')->setCategory('general');
        $this->em->persist($other);
        $this->em->flush();
        $crawler = $this->client->request('GET', self::INDEX.'/'.$other->getId().'/edit');
        self::assertNotContains('guide', $this->options($crawler, 'BotMessageTemplate[parseMode]'));
        self::assertCount(0, $crawler->filter('[data-guide-preview]'));
        $this->em->getConnection()->delete('bot_message_template', ['id' => $other->getId()]);
    }

    public function testEditingAGuideRowShowsThePreviewInItsDirection(): void
    {
        $fa = $this->guideRow('guide.faq.body', 'fa', "## پرسش\n**پاسخ** <script>alert(1)</script>");
        $crawler = $this->client->request('GET', self::INDEX.'/'.$fa->getId().'/edit');
        self::assertSame(200, $this->httpCode());
        $frame = $crawler->filter('[data-guide-preview] iframe[data-guide-frame]');
        self::assertSame('', $frame->attr('sandbox'), 'no script runs in the preview');
        $document = new Crawler((string) $frame->attr('srcdoc'));
        self::assertSame('rtl', $document->filter('html')->attr('dir'));
        self::assertSame('پاسخ', $document->filter('.guide-body strong')->text());
        self::assertCount(0, $document->filter('script'));
        self::assertStringContainsString('&lt;script&gt;', (string) $frame->attr('srcdoc'));
        self::assertSame(['guide'], $this->options($crawler, 'BotMessageTemplate[parseMode]'));
        self::assertStringContainsString('/reset', (string) $crawler->filter('a[data-guide-reset-link]')->attr('href'));

        $en = $this->guideRow('guide.faq.body', 'en', 'Answer');
        $crawler = $this->client->request('GET', self::INDEX.'/'.$en->getId().'/edit');
        self::assertSame('ltr', (new Crawler((string) $crawler->filter('iframe[data-guide-frame]')->attr('srcdoc')))->filter('html')->attr('dir'));
    }

    public function testThePreviewEndpoint(): void
    {
        $token = $this->previewToken();

        $this->client->request('POST', '/admin/bot-texts/guide/preview', ['_token' => $token, 'key' => 'guide.pay.body', 'locale' => 'en',
            'text' => "**Pay** [x](javascript:alert(1)) [ok](https://example.com) <img src=x onerror=alert(1)> \"><b>\n::: if lottery\nx\n:::\n{{ payment_methods }}"]);
        self::assertSame(200, $this->httpCode());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($result);
        $document = new Crawler((string) $result['document']);
        self::assertSame('ltr', $document->filter('html')->attr('dir'));
        self::assertSame('Pay', $document->filter('.guide-body strong')->text());
        self::assertCount(0, $document->filter('.guide-body img, .guide-body b, script, a[href^="javascript"]'));
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', (string) $result['document']);
        self::assertSame('https://example.com', $document->filter('.guide-body a')->attr('href'));
        self::assertNotEmpty($result['problems']);
        self::assertStringContainsString('unknown feature "lottery"', implode("\n", $result['problems']));
        self::assertStringContainsString('is not allowed', implode("\n", $result['problems']));

        // Persian: right to left; a title preview shows the topic's current body.
        $this->client->request('POST', '/admin/bot-texts/guide/preview', ['_token' => $token, 'key' => 'guide.faq.title', 'locale' => 'fa', 'text' => 'پرسش‌ها']);
        $result = json_decode((string) $this->client->getResponse()->getContent(), true);
        $document = new Crawler((string) $result['document']);
        self::assertSame('rtl', $document->filter('html')->attr('dir'));
        self::assertSame('پرسش‌ها', $document->filter('summary')->text());
        self::assertSame([], $result['problems']);

        // An empty body: the preview says the topic is hidden.
        $this->client->request('POST', '/admin/bot-texts/guide/preview', ['_token' => $token, 'key' => 'guide.faq.body', 'locale' => 'en', 'text' => '']);
        self::assertNotEmpty(json_decode((string) $this->client->getResponse()->getContent(), true)['notes']);

        // A switched-off feature's topic: the preview says it is hidden.
        $this->setting('wallet.enabled', 'false');
        $this->client->request('POST', '/admin/bot-texts/guide/preview', ['_token' => $token, 'key' => 'guide.wallet.body', 'locale' => 'en', 'text' => 'Wallet']);
        self::assertStringContainsString('switched off', implode("\n", json_decode((string) $this->client->getResponse()->getContent(), true)['notes']));
    }

    public function testThePreviewNeedsTheTokenAValidRequestAndThePermission(): void
    {
        $token = $this->previewToken();
        $this->client->request('POST', '/admin/bot-texts/guide/preview', ['key' => 'guide.pay.body', 'locale' => 'en', 'text' => 'x']);
        self::assertSame(403, $this->httpCode(), 'no CSRF token');
        $this->client->request('POST', '/admin/bot-texts/guide/preview', ['_token' => 'wrong', 'key' => 'guide.pay.body', 'locale' => 'en', 'text' => 'x']);
        self::assertSame(403, $this->httpCode(), 'bad CSRF token');
        $this->client->request('POST', '/admin/bot-texts/guide/preview', ['_token' => $token, 'key' => 'bot.welcome', 'locale' => 'en', 'text' => 'x']);
        self::assertSame(400, $this->httpCode(), 'not a guide key');
        $this->client->request('POST', '/admin/bot-texts/guide/preview', ['_token' => $token, 'key' => 'guide.pay.body', 'locale' => 'de', 'text' => 'x']);
        self::assertSame(400, $this->httpCode(), 'unknown language');
        $this->client->request('POST', '/admin/bot-texts/guide/preview', ['_token' => $token, 'key' => 'guide.pay.body', 'locale' => 'en', 'text' => str_repeat('a', 20001)]);
        self::assertSame(413, $this->httpCode(), 'very long text');
        $this->client->request('GET', '/admin/bot-texts/guide/preview');
        self::assertSame(405, $this->httpCode(), 'POST only');

        // Support has no BOT_TEXTS_MANAGE: 403 for the preview, the reset and the Bot Content pages.
        $row = $this->guideRow('guide.faq.body', 'en', 'Mine');
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPPORT), 'main');
        $this->client->request('POST', '/admin/bot-texts/guide/preview', ['_token' => $token, 'key' => 'guide.pay.body', 'locale' => 'en', 'text' => 'x']);
        self::assertSame(403, $this->httpCode());
        $this->client->request('GET', '/admin/bot-texts/guide/'.$row->getId().'/reset');
        self::assertSame(403, $this->httpCode());
        $this->client->request('POST', '/admin/bot-texts/guide/'.$row->getId().'/reset', ['_token' => $token]);
        self::assertSame(403, $this->httpCode());
        $this->client->request('GET', self::INDEX.'/new?key=guide.faq.body&locale=fa');
        self::assertSame(403, $this->httpCode());
        self::assertNotNull($this->em->getRepository(BotMessageTemplate::class)->find($row->getId()));
    }

    public function testResetToDefault(): void
    {
        $row = $this->guideRow('guide.faq.body', 'en', 'My own FAQ');
        $id = (int) $row->getId();
        self::assertStringContainsString('My own FAQ', (string) $this->topic('faq', 'en')['body']);

        $crawler = $this->client->request('GET', '/admin/bot-texts/guide/'.$id.'/reset');
        self::assertSame(200, $this->httpCode());
        self::assertSame('guide.faq.body', $crawler->filter('[data-reset-key]')->text());
        self::assertNotNull($this->em->getRepository(BotMessageTemplate::class)->find($id), 'GET only asks');

        $this->client->request('POST', '/admin/bot-texts/guide/'.$id.'/reset', ['_token' => 'wrong']);
        self::assertTrue($this->client->getResponse()->isRedirect());
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(BotMessageTemplate::class)->find($id), 'a bad token deletes nothing');

        $this->client->submit($crawler->filter('form[data-reset-form]')->form());
        self::assertTrue($this->client->getResponse()->isRedirect());
        self::assertStringContainsString('filters', (string) $this->client->getResponse()->headers->get('Location'));
        $this->em->clear();
        self::assertNull($this->em->getRepository(BotMessageTemplate::class)->find($id));
        $log = $this->assertAudited('bot_text.reset', (string) $id);
        self::assertSame(['key' => 'guide.faq.body', 'locale' => 'en'], $log->getDetails());
        self::assertStringNotContainsString('My own FAQ', (string) $this->topic('faq', 'en')['body'], 'the shipped default is back');

        // Only guide rows can be reset here.
        $other = (new BotMessageTemplate())->setKey('bot.welcome')->setLocale('en')->setTitle('Welcome')->setBody('Hi')->setCategory('general');
        $this->em->persist($other);
        $this->em->flush();
        $this->client->request('GET', '/admin/bot-texts/guide/'.$other->getId().'/reset');
        self::assertSame(404, $this->httpCode());
        $this->em->getConnection()->delete('bot_message_template', ['id' => $other->getId()]);
    }

    public function testEditingAGuideRowIsAudited(): void
    {
        $row = $this->guideRow('guide.faq.body', 'en', 'Before');
        $crawler = $this->client->request('GET', self::INDEX.'/'.$row->getId().'/edit');
        $this->client->submit($crawler->filter('form[name="BotMessageTemplate"]')->form(['BotMessageTemplate[body]' => 'After']));
        self::assertTrue($this->client->getResponse()->isRedirect());
        $log = $this->assertAudited('bot_text.update', (string) $row->getId());
        self::assertSame(['changed' => ['body']], $log->getDetails(), 'field names only, never the text');
    }

    /** @phpstan-impure */
    private function httpCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function previewToken(): string
    {
        $row = $this->guideRow('guide.start.body', 'en', 'Start');
        $crawler = $this->client->request('GET', self::INDEX.'/'.$row->getId().'/edit');

        return (string) $crawler->filter('[data-guide-preview]')->attr('data-token');
    }

    /**
     * @return list<string>
     */
    private function options(Crawler $crawler, string $name): array
    {
        return array_values(array_filter($crawler->filter('select[name="'.$name.'"] option')->each(static fn (Crawler $o): string => (string) $o->attr('value')), static fn (string $v): bool => '' !== $v));
    }

    private function guideRow(string $key, string $locale, string $body): BotMessageTemplate
    {
        $row = (new BotMessageTemplate())->setKey($key)->setLocale($locale)->setTitle($key)->setBody($body)->setParseMode('guide')
            ->setCategory('guide')->setIsSystem(false);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    private function savedRow(string $key, string $locale): BotMessageTemplate
    {
        $this->em->clear();
        $row = $this->em->getRepository(BotMessageTemplate::class)->findOneBy(['key' => $key, 'locale' => $locale]);
        self::assertInstanceOf(BotMessageTemplate::class, $row);

        return $row;
    }

    /**
     * @return array{slug: string, title: string, body: \Twig\Markup}
     */
    private function topic(string $slug, string $locale): array
    {
        foreach (self::getContainer()->get(MiniAppGuide::class)->topics($locale) as $topic) {
            if ($slug === $topic['slug']) {
                return $topic;
            }
        }
        self::fail($slug.' is not shown in '.$locale);
    }

    private function assertAudited(string $action, string $id): AdminAuditLog
    {
        $this->em->clear();
        $logs = $this->em->getRepository(AdminAuditLog::class)->findBy(['action' => $action, 'targetType' => 'BotMessageTemplate', 'targetId' => $id]);
        self::assertCount(1, $logs, $action);

        return $logs[0];
    }

    private function admin(string $role): AdminUser
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-'.bin2hex(random_bytes(3)).'@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }
}
