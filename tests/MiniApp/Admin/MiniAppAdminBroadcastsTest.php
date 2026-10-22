<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\BroadcastDelivery;
use App\Entity\BroadcastMessage;
use App\Entity\Setting;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RestoresTables;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Broadcasts in the Mini App admin (BROADCAST_SEND): compose with the web's rules (live preview, character count,
 * audience count), prepare, send behind the typed recipient count and the confirm token, cancel, retry failed, the
 * delivery summary (failure reasons masked), the automation switch, no edit once sending started; audit (miniapp).
 */
final class MiniAppAdminBroadcastsTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;
    use RestoresTables;

    private const BASE = '/app/api/admin/broadcasts';

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotTables('setting');
        $this->clean();
        $this->setUpAdminCast();
        // Two more customers with an active service: with the fixture's buyer, three recipients.
        foreach (['840601', '840602'] as $telegramId) {
            $this->createActiveService($this->createUser($telegramId), $this->service->getInbound());
        }
        $this->em->flush();
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->clean();
        $this->restoreTables();
        parent::tearDown();
    }

    public function testComposeIsCheckedLiveWithPreviewCountAndAudience(): void
    {
        $session = $this->authenticate(self::ADMIN);

        $check = $this->appPostJson(self::BASE.'/check', $session, ['title' => '', 'body' => '', 'targetType' => 'users_with_active_service']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['title', 'body'], array_keys((array) $check['errors']));

        $check = $this->appPostJson(self::BASE.'/check', $session, ['title' => 'x', 'body' => str_repeat('ب', 4097), 'targetType' => 'all']);
        self::assertSame(['body'], array_keys((array) $check['errors']), 'the web limit: 4096 characters');
        self::assertSame('4097 / 4096', $check['live']['length']);

        $check = $this->appPostJson(self::BASE.'/check', $session, ['title' => 'Promo', 'body' => '<b>سلام</b> <script>alert(1)</script><a href="javascript:x">لینک</a>', 'parseMode' => 'HTML', 'targetType' => 'users_with_active_service']);
        self::assertSame([], (array) $check['errors']);
        self::assertStringContainsString('<b>سلام</b>', $check['preview']);
        self::assertStringNotContainsString('<script', $check['preview']);
        self::assertStringNotContainsString('javascript:', $check['preview']);
        self::assertSame('3', $check['live']['audience'], 'the three customers with an active service');

        $check = $this->appPostJson(self::BASE.'/check', $session, ['title' => 'x', 'body' => 'y', 'targetType' => 'selected_users', 'targetUserIds' => 'abc']);
        self::assertSame(['targetUserIds'], array_keys((array) $check['errors']));
        $check = $this->appPostJson(self::BASE.'/check', $session, ['title' => 'x', 'body' => 'y', 'targetType' => 'selected_users', 'targetUserIds' => '۱, '.$this->buyer->getId()]);
        self::assertSame([], (array) $check['errors']);
        self::assertSame('1', $check['live']['audience'], 'Persian digits read; an id without a Telegram account is no recipient');
        $check = $this->appPostJson(self::BASE.'/check', $session, ['title' => 'x', 'body' => 'y', 'targetType' => 'users_with_expiring_service', 'expiringDays' => '0']);
        self::assertSame(['expiringDays'], array_keys((array) $check['errors']));
        $check = $this->appPostJson(self::BASE.'/check', $session, ['title' => 'x', 'body' => 'y', 'targetType' => 'everyone']);
        self::assertSame(['targetType'], array_keys((array) $check['errors']));

        $form = new Crawler($this->appGet('/app/admin/broadcasts/new?lang=en', $session));
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['all', 'active_users', 'users_with_active_service', 'users_with_expiring_service', 'selected_users', 'admins'], $form->filter('select[name="targetType"] option')->each(static fn (Crawler $c): string => (string) $c->attr('value')), 'the web\'s audiences');
        self::assertCount(1, $form->filter('[data-preview]'));
        self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($form->html()));
    }

    public function testPrepareThenSendNeedsTheTypedCountAndTheConfirmToken(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $broadcast = $this->create($session, ['title' => 'Promo', 'body' => 'Hello customers', 'targetType' => 'users_with_active_service']);
        self::assertSame(BroadcastMessage::STATUS_DRAFT, $broadcast->getStatus());

        $this->appPostJson(self::BASE.'/'.$broadcast->getId().'/send', $session, ['confirmCount' => '3'], self::newKey());
        self::assertSame(409, $this->httpStatus(), 'not prepared yet');

        $answer = $this->appPostJson(self::BASE.'/'.$broadcast->getId().'/prepare', $session, [], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        self::assertSame(3, $answer['recipients']);
        $page = new Crawler($this->appGet('/app/admin/broadcasts/'.$broadcast->getId(), $session));
        self::assertSame('prepared', $page->filter('[data-broadcast-status]')->attr('data-broadcast-status'));
        self::assertSame('3', trim($page->filter('[data-recipients]')->text()));

        $send = self::BASE.'/'.$broadcast->getId().'/send';
        $this->appPostJson($send, $session, [], self::newKey());
        self::assertSame(422, $this->httpStatus(), 'the count must be typed');
        $this->appPostJson($send, $session, ['confirmCount' => '2'], self::newKey());
        self::assertSame(422, $this->httpStatus(), 'and must match');
        $first = $this->appPostJson($send, $session, ['confirmCount' => '۳'], $key = self::newKey());
        self::assertSame(428, $this->httpStatus());
        self::assertStringContainsString('3', $first['confirm']['message']);
        self::assertCount(0, $this->http->requests('/sendMessage'), 'nothing sent before the confirmation');

        $this->postWithConfirm($send, $session, ['confirmCount' => '3'], $key, (string) $first['confirm']['token']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
        self::assertSame(['Hello customers', 'Hello customers', 'Hello customers'], $this->http->telegramTexts());
        $this->em->clear();
        $sent = $this->em->find(BroadcastMessage::class, $broadcast->getId());
        self::assertSame([BroadcastMessage::STATUS_SENT, 3, 3], [$sent?->getStatus(), $sent?->getTotalRecipients(), $sent?->getSentCount()]);

        // A repeat of the same request (double tap) sends nothing more.
        $this->postWithConfirm($send, $session, ['confirmCount' => '3'], $key, (string) $first['confirm']['token']);
        self::assertCount(3, $this->http->requests('/sendMessage'));

        $actions = array_column(array_filter($this->auditRows(), static fn (array $r): bool => str_starts_with((string) $r['action'], 'broadcast.')), 'source', 'action');
        self::assertSame(['broadcast.create' => 'miniapp', 'broadcast.prepare' => 'miniapp', 'broadcast.send' => 'miniapp'], $actions);
    }

    public function testNothingCanBeEditedOnceSendingStarted(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $broadcast = $this->create($session, ['title' => 'Promo', 'body' => 'v1', 'targetType' => 'users_with_active_service']);
        $broadcast->setStatus(BroadcastMessage::STATUS_SENDING);
        $this->em->flush();

        $this->appPostJson(self::BASE.'/'.$broadcast->getId(), $session, ['title' => 'Promo', 'body' => 'v2', 'targetType' => 'all'], self::newKey());
        self::assertSame(409, $this->httpStatus());
        $this->appPostJson(self::BASE.'/'.$broadcast->getId().'/prepare', $session, [], self::newKey());
        self::assertSame(409, $this->httpStatus());
        $this->em->clear();
        self::assertSame('v1', $this->em->find(BroadcastMessage::class, $broadcast->getId())?->getBody());
        $page = new Crawler($this->appGet('/app/admin/broadcasts/'.$broadcast->getId(), $session));
        self::assertCount(0, $page->filter('form#broadcast-form'), 'no form once sending started');
        self::assertCount(1, $page->filter('[data-admin-action="cancel"]'));
    }

    public function testAnEditedDraftMustBePreparedAgain(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $broadcast = $this->create($session, ['title' => 'Promo', 'body' => 'v1', 'targetType' => 'users_with_active_service']);
        $this->appPostJson(self::BASE.'/'.$broadcast->getId().'/prepare', $session, [], self::newKey());
        self::assertSame(3, $this->deliveries($broadcast));

        $this->appPostJson(self::BASE.'/'.$broadcast->getId(), $session, ['title' => 'Promo', 'body' => 'v2', 'targetType' => 'admins'], self::newKey());
        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, $this->deliveries($broadcast), 'the old recipient list is dropped');
        $this->appPostJson(self::BASE.'/'.$broadcast->getId().'/send', $session, ['confirmCount' => '3'], self::newKey());
        self::assertSame(409, $this->httpStatus());
    }

    public function testTheAutomationSwitchStopsSendAndRetry(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $broadcast = $this->create($session, ['title' => 'Promo', 'body' => 'Hi', 'targetType' => 'users_with_active_service']);
        $this->appPostJson(self::BASE.'/'.$broadcast->getId().'/prepare', $session, [], self::newKey());
        $this->em->persist((new Setting())->setKeyName('automation.broadcasts_enabled')->setValue('false')->setType('boolean'));
        $this->em->flush();

        $answer = $this->appPostJson(self::BASE.'/'.$broadcast->getId().'/send', $session, ['confirmCount' => '3'], self::newKey());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('broadcasts_paused', $answer['error']);
        self::assertCount(0, $this->http->requests('/sendMessage'));
        self::assertCount(1, (new Crawler($this->appGet('/app/admin/broadcasts/'.$broadcast->getId(), $session)))->filter('[data-paused]'));

        $broadcast->setStatus(BroadcastMessage::STATUS_FAILED)->setFailedCount(1);
        $this->em->flush();
        $this->appPostJson(self::BASE.'/'.$broadcast->getId().'/retry', $session, [], self::newKey());
        self::assertSame(409, $this->httpStatus());
    }

    public function testFailuresAreSummarisedMaskedAndRetried(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $blocked = '840601';
        $this->http->on('/sendMessage', static function (string $method, string $url, array $options) use ($blocked): MockResponse {
            $body = json_decode((string) ($options['body'] ?? '{}'), true);
            $chat = is_array($body) ? (string) ($body['chat_id'] ?? '') : '';
            if ($chat === $blocked) {
                return new MockResponse('{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user (https://api.telegram.org/bot100000001:test-only-fake-token/sendMessage)"}', ['http_code' => 403, 'response_headers' => ['content-type' => 'application/json']]);
            }

            return new MockResponse('{"ok":true,"result":{"message_id":7}}', ['response_headers' => ['content-type' => 'application/json']]);
        });
        $broadcast = $this->create($session, ['title' => 'Promo', 'body' => 'Hi', 'targetType' => 'users_with_active_service']);
        $this->appPostJson(self::BASE.'/'.$broadcast->getId().'/prepare', $session, [], self::newKey());
        $this->confirmedPost(self::BASE.'/'.$broadcast->getId().'/send', $session, ['confirmCount' => '3']);
        self::assertSame(200, $this->httpStatus());

        $page = $this->appGet('/app/admin/broadcasts/'.$broadcast->getId(), $session);
        $crawler = new Crawler($page);
        self::assertSame('failed', $crawler->filter('[data-broadcast-status]')->attr('data-broadcast-status'));
        self::assertSame(['2', '1', '0'], [trim($crawler->filter('[data-count="sent"]')->text()), trim($crawler->filter('[data-count="failed"]')->text()), trim($crawler->filter('[data-count="pending"]')->text())]);
        self::assertCount(1, $crawler->filter('[data-failure]'));
        self::assertStringContainsString('blocked', $crawler->filter('[data-failure]')->text());
        self::assertStringNotContainsString('test-only-fake-token', $page, 'the bot token in the error is masked');
        self::assertStringNotContainsString('api.telegram.org', $page);

        $this->http->reset();
        $answer = $this->confirmedPost(self::BASE.'/'.$broadcast->getId().'/retry', $session);
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        self::assertCount(1, $this->http->requests('/sendMessage'), 'only the failed delivery again');
        $this->em->clear();
        self::assertSame([BroadcastMessage::STATUS_SENT, 3, 0], [$this->em->find(BroadcastMessage::class, $broadcast->getId())?->getStatus(), $this->em->find(BroadcastMessage::class, $broadcast->getId())?->getSentCount(), $this->em->find(BroadcastMessage::class, $broadcast->getId())?->getFailedCount()]);
        self::assertContains('broadcast.retry_failed', array_column($this->auditRows(), 'action'));
    }

    public function testCancelIsConfirmedAndOnlyWhileNotFinished(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $broadcast = $this->create($session, ['title' => 'Promo', 'body' => 'Hi', 'targetType' => 'all']);
        $this->confirmedPost(self::BASE.'/'.$broadcast->getId().'/cancel', $session);
        self::assertSame(200, $this->httpStatus());
        $this->em->clear();
        self::assertSame(BroadcastMessage::STATUS_CANCELLED, $this->em->find(BroadcastMessage::class, $broadcast->getId())?->getStatus());
        $this->confirmedPost(self::BASE.'/'.$broadcast->getId().'/cancel', $session);
        self::assertSame(409, $this->httpStatus(), 'already cancelled');
        self::assertSame(1, count(array_filter($this->auditRows(), static fn (array $r): bool => 'broadcast.cancel' === $r['action'] && 'miniapp' === $r['source'])));
    }

    public function testTheTestSendGoesToTheAdminOnly(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $broadcast = $this->create($session, ['title' => 'Promo', 'body' => 'Preview me', 'targetType' => 'all']);
        $answer = $this->appPostJson(self::BASE.'/'.$broadcast->getId().'/test', $session, [], self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        $requests = $this->http->requests('/sendMessage');
        self::assertCount(1, $requests);
        self::assertSame(self::ADMIN, (string) json_decode((string) $requests[0]['options']['body'], true)['chat_id']);
        self::assertSame(0, $this->deliveries($broadcast), 'no delivery rows, the broadcast stays a draft');
        self::assertContains('broadcast.test_send', array_column($this->auditRows(), 'action'));
    }

    public function testADoubleSubmitCreatesOneBroadcastAndCsrfIsRequired(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $key = self::newKey();
        $body = ['title' => 'Once', 'body' => 'Hi', 'targetType' => 'all'];
        $this->appPostJson(self::BASE, $session, $body, $key);
        $this->appPostJson(self::BASE, $session, $body, $key);
        self::assertSame(1, $this->em->getRepository(BroadcastMessage::class)->count(['title' => 'Once']));

        $this->appPostJson(self::BASE, $session, ['title' => 'Csrf'] + $body, self::newKey(), 'wrong-csrf');
        self::assertSame(403, $this->httpStatus());
        self::assertSame(0, $this->em->getRepository(BroadcastMessage::class)->count(['title' => 'Csrf']));
    }

    public function testTheListShowsStatusCountsAndProgress(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $draft = $this->create($session, ['title' => 'Draft one', 'body' => 'Hi', 'targetType' => 'all']);
        $sending = $this->create($session, ['title' => 'Going out', 'body' => 'Hi', 'targetType' => 'all']);
        $sending->setStatus(BroadcastMessage::STATUS_SENDING)->setTotalRecipients(10)->setSentCount(4)->setFailedCount(1);
        $this->em->flush();

        foreach (['', '?lang=en'] as $lang) {
            $list = new Crawler($this->appGet('/app/admin/broadcasts'.$lang, $session));
            self::assertSame(200, $this->httpStatus());
            self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($list->html()));
        }
        $row = $list->filter('[data-broadcast-id="'.$sending->getId().'"]');
        self::assertSame('sending', $row->attr('data-state'));
        self::assertSame('50', $row->filter('progress')->attr('value'), '(4 sent + 1 failed) of 10');
        self::assertSame('draft', $list->filter('[data-broadcast-id="'.$draft->getId().'"]')->attr('data-state'));
        self::assertSame(['Going out'], (new Crawler($this->appGet('/app/admin/broadcasts?tab=sending', $session)))->filter('[data-broadcast-id] .title')->each(static fn (Crawler $c): string => trim($c->text())));
    }

    /**
     * @param array{token: string, csrf: string} $session
     * @param array<string, string>              $body
     */
    private function create(array $session, array $body): BroadcastMessage
    {
        $answer = $this->appPostJson(self::BASE, $session, $body, self::newKey());
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        $broadcast = $this->em->getRepository(BroadcastMessage::class)->findOneBy(['title' => $body['title']], ['id' => 'DESC']);
        self::assertInstanceOf(BroadcastMessage::class, $broadcast);
        self::assertStringEndsWith('/app/admin/broadcasts/'.$broadcast->getId(), (string) $answer['redirect']);

        return $broadcast;
    }

    private function deliveries(BroadcastMessage $broadcast): int
    {
        return $this->em->getRepository(BroadcastDelivery::class)->count(['broadcast' => $broadcast->getId()]);
    }

    private function clean(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM broadcast_delivery');
        $connection->executeStatement('DELETE FROM broadcast_message');
        $connection->executeStatement("DELETE FROM setting WHERE key_name = 'automation.broadcasts_enabled'");
    }
}
