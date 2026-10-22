<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\User;
use App\Entity\VpnService;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\MissingTranslationRecorder;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The customer's Mini App: home summary, services list and detail, the owner's self-service actions (the bot's own
 * logic, ownership checked on every id), the apps page (the subscription page's partials) and help. No response
 * carries what only the panel or the subscription page may show.
 */
final class MiniAppCustomerTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppTestTrait;

    private const SUB_URL = 'https://sub.panel-shop.com:2096/sub/k3j9x7q2m1zt5w8a';
    private const SUB_ID = 'k3j9x7q2m1zt5w8a';
    private const UUID = 'b831381d-6324-4d53-ad4f-8cda48b30811';
    private const CONFIG = 'vless://b831381d-6324-4d53-ad4f-8cda48b30811@edge.panel-shop.com:443?type=tcp&security=none#Germany-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM mini_app_session');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->startMiniAppClient();
    }

    public function testHomeSummarisesActiveServicesNearestExpiryAndLowTraffic(): void
    {
        $user = $this->createUser('8401');
        $this->service($user)->setExpiresAt(new \DateTimeImmutable('+20 days 1 hour'));
        $this->service($user)->setExpiresAt(new \DateTimeImmutable('+3 days 1 hour'))->setTrafficUsedBytes(46 * 1073741824);
        $this->service($user)->setStatus(VpnServiceStatus::EXPIRED);
        $this->em->flush();

        $html = $this->appGet('/app/view/home', $this->authenticate('8401', 'Sara'));

        self::assertSame(200, $this->httpStatus());
        self::assertStringContainsString('Sara', $html, 'greeting');
        $summary = (new Crawler($html))->filter('[data-summary]');
        self::assertSame('2', $summary->attr('data-active-count'), 'expired services do not count');
        self::assertSame('3', $summary->attr('data-nearest-days'));
        self::assertCount(1, (new Crawler($html))->filter('[data-low-traffic]'), '92% used');
        self::assertCount(1, (new Crawler($html))->filter('a[data-nav][href="/app/view/services"]'), 'quick action');
    }

    public function testHomeWithoutServicesPointsToTheBot(): void
    {
        $html = $this->appGet('/app/view/home', $this->authenticate('8402'));

        self::assertSame('0', (new Crawler($html))->filter('[data-summary]')->attr('data-active-count'));
        self::assertCount(1, (new Crawler($html))->filter('[data-no-services]'));
    }

    public function testTheServicesListShowsOnlyTheOwnersLiveServices(): void
    {
        $user = $this->createUser('8403');
        $active = $this->service($user);
        $suspended = $this->service($user)->setStatus(VpnServiceStatus::SUSPENDED);
        $deleted = $this->service($user)->setStatus(VpnServiceStatus::DELETED);
        $other = $this->service($this->createUser('8404'));
        $this->em->flush();

        $html = $this->appGet('/app/view/services', $this->authenticate('8403'));

        $ids = (new Crawler($html))->filter('[data-service-id]')->each(static fn (Crawler $c): int => (int) $c->attr('data-service-id'));
        self::assertSame([(int) $suspended->getId(), (int) $active->getId()], $ids, 'newest first, like the bot');
        self::assertNotContains((int) $deleted->getId(), $ids);
        self::assertNotContains((int) $other->getId(), $ids);
    }

    public function testTheDetailShowsStatusExpiryTrafficPlanAndTheOwnersActions(): void
    {
        $service = $this->service($this->createUser('8405'));
        $service->setExpiresAt(new \DateTimeImmutable('+20 days 1 hour'))->setTrafficUsedBytes(10 * 1073741824);
        $this->em->flush();

        $html = $this->appGet('/app/view/services/'.$service->getId(), $this->authenticate('8405'));
        $page = new Crawler($html);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('services', $page->filter('.view-root')->attr('data-view'));
        self::assertNotNull($page->filter('.view-root')->attr('data-back'), 'Telegram BackButton');
        self::assertSame('active', $page->filter('[data-status]')->attr('data-status'), 'the subscription page status component');
        self::assertSame('20', $page->filter('[data-days-left]')->attr('data-days-left'));
        self::assertSame('20', $page->filter('[data-used-percent]')->attr('data-used-percent'));
        self::assertStringContainsString('Test plan', $html);

        $pageUrl = 'http://localhost/s/'.$service->getPublicToken();
        self::assertSame($pageUrl, $page->filter('a[data-open-link]')->attr('href'), 'subscription page, inside Telegram');
        self::assertSame($pageUrl, $page->filter('button[data-copy]')->attr('data-copy'));
        $actions = $page->filter('button[data-action]')->each(static fn (Crawler $c): string => (string) $c->attr('data-action'));
        $base = '/app/api/services/'.$service->getId();
        self::assertSame([$base.'/send-link', $base.'/send-qr', $base.'/resend-config', $base.'/refresh'], $actions);
    }

    public function testAServiceThatDoesNotWorkOffersNoLinksOnlyRefresh(): void
    {
        $service = $this->service($this->createUser('8406'))->setStatus(VpnServiceStatus::EXPIRED);
        $this->em->flush();

        $page = new Crawler($this->appGet('/app/view/services/'.$service->getId(), $this->authenticate('8406')));

        self::assertSame('expired', $page->filter('[data-status]')->attr('data-status'));
        self::assertSame(['/app/api/services/'.$service->getId().'/refresh'], $page->filter('button[data-action]')->each(static fn (Crawler $c): string => (string) $c->attr('data-action')));
    }

    public function testUserACannotReadOrActOnUserBsService(): void
    {
        $theirs = $this->service($this->createUser('8408'));
        $deletedOwn = $this->service($this->createUser('8407'))->setStatus(VpnServiceStatus::DELETED);
        $this->em->flush();
        $session = $this->authenticate('8407');

        foreach ([(int) $theirs->getId(), (int) $deletedOwn->getId(), 999999999] as $id) {
            $this->appGet('/app/view/services/'.$id, $session);
            self::assertSame(404, $this->httpStatus(), 'view '.$id);
            foreach (['send-link', 'send-qr', 'resend-config', 'refresh'] as $action) {
                $this->appPost('/app/api/services/'.$id.'/'.$action, $session);
                self::assertSame(404, $this->httpStatus(), $action.' '.$id);
            }
        }
        $this->appGet('/app/view/services/abc', $session);
        self::assertSame(404, $this->httpStatus());

        self::assertSame([], $this->http->requests('/sendMessage'), 'nothing was sent to anybody');
        self::assertSame([], $this->http->requests('/sendPhoto'));
    }

    public function testResendConfigAndSendLinkDeliverToTheOwnersChatThroughTheBot(): void
    {
        $service = $this->service($this->createUser('8409'));
        $session = $this->authenticate('8409');

        $this->appPost('/app/api/services/'.$service->getId().'/resend-config', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertNotSame('', (string) ($this->json()['message'] ?? ''));
        $sent = $this->sentTo('8409');
        self::assertNotEmpty($sent);
        self::assertTrue($this->anyContains($sent, self::CONFIG), 'the bot\'s config message, in the chat');

        $this->http->reset();
        $this->appPost('/app/api/services/'.$service->getId().'/send-link', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertTrue($this->anyContains($this->sentTo('8409'), self::SUB_URL));

        $this->http->reset();
        $this->appPost('/app/api/services/'.$service->getId().'/send-qr', $session);
        self::assertSame(200, $this->httpStatus());
        self::assertNotEmpty($this->http->requests('/sendPhoto'), 'QR image to the chat');
    }

    public function testActionsNeedTheCsrfToken(): void
    {
        $service = $this->service($this->createUser('8410'));
        $session = $this->authenticate('8410');

        $this->appPost('/app/api/services/'.$service->getId().'/resend-config', $session, 'forged');

        self::assertSame(403, $this->httpStatus());
        self::assertSame([], $this->http->requests('/sendMessage'));
    }

    public function testRefreshSyncsUsageFromThePanel(): void
    {
        $service = $this->service($this->createUser('8411'));
        $this->driver()->usage = new VpnUsage(trafficUsedGb: 5, trafficLimitGb: 50, usedBytes: 5 * 1073741824, totalBytes: 50 * 1073741824);
        $session = $this->authenticate('8411');

        $this->appPost('/app/api/services/'.$service->getId().'/refresh', $session);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(5 * 1073741824, (int) $this->row('vpn_service', (int) $service->getId())['traffic_used_bytes']);

        $this->driver()->usageFails = true;
        $this->appPost('/app/api/services/'.$service->getId().'/refresh', $session);
        self::assertSame(502, $this->httpStatus());
        self::assertNotSame('', (string) ($this->json()['message'] ?? ''));
    }

    public function testTheAppsPageReusesTheSubscriptionPagePartialsWithoutImportLinks(): void
    {
        $html = $this->appGet('/app/view/apps?platform=android', $this->authenticate('8412'));
        $page = new Crawler($html);

        self::assertSame(200, $this->httpStatus());
        self::assertGreaterThan(0, $page->filter('.apps [data-app]')->count(), 'client apps from the shared _apps partial');
        self::assertSame('/app/view/apps?platform=android', $page->filter('.platforms a.current')->attr('href'));
        self::assertNotNull($page->filter('.platforms a.current')->attr('data-nav'), 'platform switch stays in the app');
        self::assertCount(0, $page->filter('[data-deeplink]'), 'import links need a subscription: on the subscription page only');
        self::assertCount(0, $page->filter('[data-copy]'));
        self::assertCount(1, $page->filter('[data-apps-import-hint]'));
    }

    public function testHelpShowsTheBotsSupportText(): void
    {
        $html = $this->appGet('/app/view/help', $this->authenticate('8413'));

        self::assertStringContainsString(htmlspecialchars($this->botText('bot.support'), ENT_QUOTES), $html);
    }

    public function testNoResponseCarriesPanelAddressesSubIdsLinksOrTokens(): void
    {
        $service = $this->service($this->createUser('8414'));
        $this->driver()->usage = new VpnUsage(trafficUsedGb: 1, trafficLimitGb: 50, usedBytes: 1073741824, totalBytes: 50 * 1073741824);
        $id = (int) $service->getId();
        $responses = [];

        $this->postAuth($this->initData('8414'));
        $session = ['token' => (string) $this->json()['token'], 'csrf' => (string) $this->json()['csrfToken']];
        foreach (['/app', '/app/view/nav', '/app/view/home', '/app/view/services', '/app/view/services/'.$id, '/app/view/apps', '/app/view/help', '/app/api/me'] as $path) {
            $responses[$path] = $this->appGet($path, '/app' === $path ? null : $session);
            self::assertSame(200, $this->httpStatus(), $path);
        }
        foreach (['send-link', 'send-qr', 'resend-config', 'refresh'] as $action) {
            $responses[$action] = $this->appPost('/app/api/services/'.$id.'/'.$action, $session);
            self::assertSame(200, $this->httpStatus(), $action);
        }

        $secrets = [self::SUB_URL, 'panel-shop.com', self::SUB_ID, self::UUID, 'existing_user', 'dummy_existing', $this->botToken(), $session['token'], 'panel.test'];
        foreach ($responses as $path => $content) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $content, sprintf('%s leaks "%s"', $path, $secret === $session['token'] ? 'the session token' : $secret));
            }
        }
    }

    public function testTheCustomerPagesAreTranslatedInBothLanguages(): void
    {
        $recorder = self::getContainer()->get(MissingTranslationRecorder::class);
        $recorder->reset();
        $user = $this->createUser('8415');
        $active = $this->service($user)->setTrafficUsedBytes(49 * 1073741824);
        $expired = $this->service($user)->setStatus(VpnServiceStatus::EXPIRED);
        $this->em->flush();
        $session = $this->authenticate('8415');

        foreach (['', '?lang=en'] as $query) {
            foreach (['/app/view/home', '/app/view/services', '/app/view/services/'.$active->getId(), '/app/view/services/'.$expired->getId(), '/app/view/apps'] as $path) {
                $this->appGet($path.$query, $session);
                self::assertSame(200, $this->httpStatus(), $path.$query);
            }
            $this->appPost('/app/api/services/'.$active->getId().'/resend-config'.$query, $session);
        }

        self::assertSame([], $recorder->missing());
    }

    private function service(User $user): VpnService
    {
        $service = $this->createActiveService($user, $this->createTestInbound())
            ->setSubscriptionUrl(self::SUB_URL)
            ->setSubId(self::SUB_ID)
            ->setClientUuid(self::UUID)
            ->setConfigLinks([self::CONFIG])
            ->setConfigText(self::CONFIG);
        $service->setOrder($this->createOrder($service->getUser(), $this->createPlan()));
        $this->em->flush();
        $this->http->reset();

        return $service;
    }

    /**
     * Texts the bot sent to this chat since the last reset.
     *
     * @return list<string>
     */
    private function sentTo(string $chatId): array
    {
        $texts = [];
        foreach ($this->http->requests('/sendMessage') as $request) {
            $body = $this->requestBody($request);
            if ($chatId === (string) ($body['chat_id'] ?? '')) {
                $texts[] = (string) ($body['text'] ?? '');
            }
        }

        return $texts;
    }

    /**
     * @param list<string> $texts
     */
    private function anyContains(array $texts, string $needle): bool
    {
        foreach ($texts as $text) {
            if (str_contains(html_entity_decode($text), $needle)) {
                return true;
            }
        }

        return false;
    }
}
