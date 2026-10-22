<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Admin\Application\SettingsAdministration;
use App\Entity\RequiredChannel;
use App\Entity\Setting;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\SettingsFormCases;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Settings in the Mini App admin (SETTINGS_MANAGE, super admin only): the web settings page's two forms with the same
 * keys and the same answers (SettingsFormCases, also run against the web by SettingsSaveCharacterizationTest), only
 * the allow-listed keys and nothing secret on the page; required channels with the entity's rules (shared with the
 * web form): list, add, enable / disable, remove after confirming.
 */
final class MiniAppAdminSettingsTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private const PREFIXES = ['renewal.', 'pricing.', 'traffic_addon.', 'sales.', 'automation.', 'orders.', 'settings_test.'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    /**
     * @param array<string, string|null> $changes
     * @param array<string, string>      $stored
     */
    #[DataProvider('cases')]
    public function testTheSameInputGetsTheSameAnswerAsOnTheWeb(string $tab, array $changes, ?string $error, array $stored): void
    {
        $session = $this->authenticate(self::SUPER);
        $path = '/app/api/admin/settings/'.$tab;
        $input = SettingsFormCases::input($tab, $changes);

        if (null !== $error) {
            $answer = $this->appPostJson($path, $session, $input, self::newKey());
            self::assertSame(422, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
            $message = self::getContainer()->get('translator')->trans($error, [], 'admin', 'fa');
            self::assertSame([$message], array_values((array) $answer['errors'])[0], 'the web\'s message, on its field');
            self::assertSame([], $this->settings(), 'nothing saved');

            return;
        }
        $answer = $this->confirmedPost($path, $session, $input);
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        $saved = $this->settings();
        foreach ($stored as $key => $value) {
            self::assertSame($value, $saved[$key] ?? null, $key);
        }
        $audit = array_values(array_filter($this->auditRows(), static fn (array $r): bool => 'settings.update' === $r['action']));
        self::assertCount(1, $audit);
        self::assertSame('miniapp', $audit[0]['source']);
    }

    /**
     * @return iterable<string, array{string, array<string, string|null>, string|null, array<string, string>}>
     */
    public static function cases(): iterable
    {
        return SettingsFormCases::cases();
    }

    public function testThePageHasOnlyTheAllowListedKeysAndNoSecret(): void
    {
        $this->em->persist((new Setting())->setKeyName('settings_test.api_token')->setValue('do-not-render-me')->setType('text'));
        $this->em->flush();
        $session = $this->authenticate(self::SUPER);
        foreach (['', '?lang=en'] as $lang) {
            $html = $this->appGet('/app/admin/settings'.$lang, $session);
            self::assertSame(200, $this->httpStatus());
            self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($html));
            self::assertStringNotContainsString('do-not-render-me', $html);
            self::assertStringNotContainsString('settings_test.', $html);
        }
        $page = new Crawler($html);
        $names = array_unique($page->filter('form[data-settings] [name]')->each(static fn (Crawler $c): string => (string) $c->attr('name')));
        sort($names);
        $allowed = array_merge(array_keys(SettingsAdministration::SALES_KEYS), array_keys(SettingsAdministration::AUTOMATION_KEYS));
        sort($allowed);
        self::assertSame($allowed, $names, 'exactly the web forms\' fields');
        self::assertSame(['sales', 'automation'], $page->filter('form[data-settings]')->each(static fn (Crawler $c): string => (string) $c->attr('data-settings')));
    }

    public function testOnlyTheSuperAdminManagesSettings(): void
    {
        foreach ([self::ADMIN, self::SUPPORT] as $who) {
            $session = $this->authenticate($who);
            $this->appGet('/app/admin/settings', $session);
            self::assertSame(403, $this->httpStatus());
            $this->appPostJson('/app/api/admin/settings/sales', $session, SettingsFormCases::input('sales', []), self::newKey());
            self::assertSame(403, $this->httpStatus());
        }
        self::assertSame([], $this->settings());
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('channelCases')]
    public function testRequiredChannelsAreValidatedByTheEntitysRules(array $input, ?string $field): void
    {
        $this->em->persist((new RequiredChannel())->setTitle('Existing')->setChatId('@taken_channel'));
        $this->em->flush();
        $session = $this->authenticate(self::SUPER);
        $answer = $this->appPostJson('/app/api/admin/channels', $session, $input + ['title' => 'News', 'isActive' => '1', 'requireForPurchase' => '1'], self::newKey());

        if (null === $field) {
            self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
            self::assertSame(2, $this->em->getRepository(RequiredChannel::class)->count([]));

            return;
        }
        self::assertSame(422, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        self::assertArrayHasKey($field, (array) $answer['errors']);
        self::assertSame(1, $this->em->getRepository(RequiredChannel::class)->count([]));

        // The web form validates the same entity: the same field is refused there.
        $channel = (new RequiredChannel())->setTitle($input['title'] ?? 'News')->setChatId($input['chatId'] ?? '')->setInviteUrl(($input['inviteUrl'] ?? '') ?: null);
        $paths = array_map(static fn ($v): string => $v->getPropertyPath(), iterator_to_array(self::getContainer()->get(ValidatorInterface::class)->validate($channel)));
        self::assertContains($field, $paths);
    }

    /**
     * @return iterable<string, array{array<string, string>, string|null}>
     */
    public static function channelCases(): iterable
    {
        yield 'a public channel' => [['chatId' => '@my_news', 'inviteUrl' => 'https://t.me/my_news'], null];
        yield 'a private channel by id' => [['chatId' => '-1001234567890', 'inviteUrl' => 'https://t.me/+AbCdEf123'], null];
        yield 'no link' => [['chatId' => '@my_news'], null];
        yield 'empty chat id' => [['chatId' => ''], 'chatId'];
        yield 'chat id without @' => [['chatId' => 'my_news'], 'chatId'];
        yield 'too short a username' => [['chatId' => '@abc'], 'chatId'];
        yield 'a link as chat id' => [['chatId' => 'https://t.me/my_news'], 'chatId'];
        yield 'the same channel twice' => [['chatId' => '@taken_channel'], 'chatId'];
        yield 'empty title' => [['chatId' => '@my_news', 'title' => ' '], 'title'];
        yield 'http link' => [['chatId' => '@my_news', 'inviteUrl' => 'http://t.me/my_news'], 'inviteUrl'];
        yield 'javascript link' => [['chatId' => '@my_news', 'inviteUrl' => 'javascript:alert(1)'], 'inviteUrl'];
        yield 'not a Telegram link' => [['chatId' => '@my_news', 'inviteUrl' => 'https://evil.example/join'], 'inviteUrl'];
    }

    public function testChannelsAreToggledAndRemovedAfterConfirming(): void
    {
        $channel = (new RequiredChannel())->setTitle('News')->setChatId('@my_news')->setInviteUrl('https://t.me/my_news');
        $this->em->persist($channel);
        $this->em->flush();
        $session = $this->authenticate(self::SUPER);

        $list = new Crawler($this->appGet('/app/admin/settings/channels?lang=en', $session));
        self::assertSame(200, $this->httpStatus());
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($list->html()));
        self::assertSame('1', $list->filter('[data-channel-id="'.$channel->getId().'"]')->attr('data-active'));

        $this->confirmedPost('/app/api/admin/channels/'.$channel->getId().'/deactivate', $session);
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();
        self::assertFalse($this->em->find(RequiredChannel::class, $channel->getId())?->isActive());
        $this->confirmedPost('/app/api/admin/channels/'.$channel->getId().'/activate', $session);
        self::assertSame(200, $this->httpStatus());

        $this->appPostJson('/app/api/admin/channels/'.$channel->getId().'/delete', $session, [], self::newKey());
        self::assertSame(428, $this->httpStatus(), 'removing asks first');
        $this->confirmedPost('/app/api/admin/channels/'.$channel->getId().'/delete', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, $this->em->getRepository(RequiredChannel::class)->count([]));
        $actions = array_column(array_filter($this->auditRows(), static fn (array $r): bool => str_starts_with((string) $r['action'], 'required_channel.')), 'source', 'action');
        self::assertSame(['required_channel.deactivate' => 'miniapp', 'required_channel.activate' => 'miniapp', 'required_channel.delete' => 'miniapp'], $actions);
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        $where = implode(' OR ', array_fill(0, count(self::PREFIXES) - 1, 'key_name LIKE ?'));

        return array_map('strval', $this->em->getConnection()->fetchAllKeyValue('SELECT key_name, value FROM setting WHERE '.$where, array_map(static fn (string $p): string => $p.'%', array_slice(self::PREFIXES, 0, -1))));
    }

    private function clean(): void
    {
        foreach (self::PREFIXES as $prefix) {
            $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name LIKE ?', [$prefix.'%']);
        }
        $this->em->getConnection()->executeStatement('DELETE FROM required_channel');
    }
}
