<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Bot\Application\ServiceManagementService;
use App\Broadcast\BroadcastSender;
use App\Entity\BroadcastMessage;
use App\Entity\Setting;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\ServiceExpiryChecker;
use App\Provisioning\Application\ServiceNotificationService;
use App\Provisioning\Application\ServiceUsageSyncService;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\CapturesErrorLog;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * app:panel:import-clients (docs/design/panel-client-import.md): clients of a modern 3x-ui panel become services of
 * their Telegram owners, visible in the bot and the Mini App; nothing is written to the panel, no order, payment or
 * notification is created; a rerun imports nothing; and no Telegram id, UUID, email, subId or link is printed or logged.
 */
final class PanelImportClientsCommandTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use CapturesErrorLog;
    use ConsoleOutputAssertions;
    use MiniAppTestTrait;

    private const TG = '512345678';
    private const GB = 1073741824;
    /** Settings this test writes (IntegrationTestCase does not remove them). */
    private const SETTINGS = ['traffic_addon.price_per_gb', 'sales.renewals_enabled', 'sales.add_traffic_enabled'];

    private Fake3xuiPanel $fake;
    private VpnPanel $panel;
    private VpnInbound $reality;
    private VpnInbound $vmess;
    /** @var array<string, array{uuid: string, subId: string}> email => identity, for the secret checks */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new Fake3xuiPanel();
        $this->http->on('panel.example.test', $this->fake);
        $this->panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('old panel')->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setSubscriptionBaseUrl('https://sub.example.com')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        // Remote inbounds 1 and 2 are synced; 3 is not.
        $this->reality = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('1')->setTitle('reality')->setProtocol('vless');
        $this->vmess = (new VpnInbound())->setPanel($this->panel)->setRemoteInboundId('2')->setTitle('vmess')->setProtocol('vmess');
        $this->em->persist($this->panel);
        $this->em->persist($this->reality);
        $this->em->persist($this->vmess);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->em) && $this->em->isOpen()) {
            $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name IN (?)', [self::SETTINGS], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        }
        parent::tearDown();
    }

    public function testAClientWithAnOwnerBecomesTheirServiceInTheBotAndTheMiniApp(): void
    {
        $this->client('old-customer-ali', self::TG, [1], ['expiryTime' => $this->ms('+20 days'), 'totalGB' => 50 * self::GB, 'limitIp' => 2], 5 * self::GB);

        [$code, $out] = $this->import();

        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 1, imported: 1, newUsers: 1, existingUsers: 0);
        $service = $this->onlyService();
        $row = $this->row('vpn_service', (int) $service->getId());
        self::assertNull($row['order_id']);
        self::assertNull($row['sold_by_reseller_id']);
        self::assertNotNull($row['imported_at']);
        self::assertTrue($service->isImported());
        self::assertSame(VpnServiceStatus::ACTIVE, $service->getStatus());
        self::assertSame((int) $this->reality->getId(), (int) $row['inbound_id']);
        self::assertSame((int) $this->panel->getId(), (int) $row['panel_id']);
        self::assertSame('old-customer-ali', $service->getClientEmail());
        self::assertSame($this->made['old-customer-ali']['uuid'], $service->getClientUuid());
        self::assertSame($this->made['old-customer-ali']['subId'], $service->getSubId());
        self::assertSame(2, $service->getIpLimit());
        self::assertSame(50 * self::GB, $service->getTrafficLimitBytes());
        self::assertSame(5 * self::GB, $service->getTrafficUsedBytes());
        self::assertSame(50, $service->getTrafficLimitGb());
        self::assertSame(5, $service->getTrafficUsedGb());
        self::assertEqualsWithDelta((new \DateTimeImmutable('+20 days'))->getTimestamp(), $service->getExpiresAt()?->getTimestamp(), 5);
        self::assertSame(43, strlen($service->getPublicToken()));
        self::assertStringEndsWith('/sub/'.$this->made['old-customer-ali']['subId'], (string) $service->getSubscriptionUrl());
        self::assertCount(1, $service->getConfigLinks() ?? [], 'the panel\'s own links are stored');
        $ref = (new Sanaei3xuiRemoteIdParser())->parse((string) $service->getRemoteId());
        self::assertNotNull($ref);
        self::assertSame('old-customer-ali', $ref->email);
        self::assertSame('1', $ref->inboundId);
        // The owner: a new account, as a first message would create it, but not "active" until they write.
        $account = $this->em->getConnection()->fetchAssociative('SELECT * FROM telegram_account WHERE telegram_id = ?', [self::TG]);
        self::assertIsArray($account);
        self::assertNull($account['last_activity_at']);
        self::assertSame((int) $account['user_id'], $service->getUser()->getId());
        // Nothing on the panel changed; nothing was sold.
        self::assertSame([], array_values(array_filter($this->fake->requestLines(), static fn (string $l): bool => !str_starts_with($l, 'GET '))), 'only GETs');
        $this->assertNothingSold();
        self::assertSame(0, $this->rows('service_notification_log'), 'an active service with nothing to warn about');

        // The bot's "My services" (the owner's first message resolves to that same account).
        $this->pressButton(self::TG, 'my_services');
        self::assertContains('service_view:'.$service->getId(), $this->sentCallbacks());
        $this->pressButton(self::TG, 'service_view:'.$service->getId());
        self::assertTrue($this->sentTextContains('سرویس #'.$service->getId()));
        self::assertSame(1, $this->rows('telegram_account'));
        self::assertNotNull($this->em->getConnection()->fetchOne('SELECT last_activity_at FROM telegram_account WHERE telegram_id = ?', [self::TG]), 'the message found the imported account');

        // The Mini App.
        $this->startMiniAppClient();
        $session = $this->authenticate(self::TG);
        self::assertStringContainsString('/app/view/services/'.$service->getId(), $this->appGet('/app/view/services', $session));
        $page = $this->appGet('/app/view/services/'.$service->getId(), $session);
        self::assertSame(200, $this->httpStatus(), $page);
        self::assertStringContainsString('data-new-plan', $page);
        self::assertSame(1, $this->rows('telegram_account'));
        self::assertSame(1, $this->rows('`user`'));
    }

    public function testAnExistingAccountIsReused(): void
    {
        $user = $this->createUser(self::TG);
        $this->client('old-1', self::TG);
        $this->client('old-2', self::TG, [2]);

        [$code, $out] = $this->import();

        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 2, imported: 2, newUsers: 0, existingUsers: 1);
        self::assertSame([$user->getId(), $user->getId()], array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT user_id FROM vpn_service')));
        self::assertSame(1, $this->rows('`user`'));
    }

    public function testASecondRunImportsNothingAndLimitContinues(): void
    {
        $this->client('a-1', '700000001');
        $this->client('a-2', '700000002');
        $this->client('a-3', '700000001');

        [$code, $out] = $this->import(['--limit' => '2']);
        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 2, imported: 2, newUsers: 2);
        self::assertConsoleContains('Stopped at --limit', $out);

        [, $out] = $this->import();
        $this->assertCounts($out, read: 3, imported: 1, newUsers: 0, existingUsers: 1, alreadyKnown: 2);

        [$code, $out] = $this->import();
        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 3, imported: 0, newUsers: 0, existingUsers: 0, alreadyKnown: 3);
        self::assertSame(3, $this->rows('vpn_service'));
        self::assertSame(2, $this->rows('telegram_account'));
    }

    public function testAClientKnownByUuidEmailOrSubIdIsLeftAlone(): void
    {
        $other = (new VpnPanel())->setType('sanaei_3xui')->setTitle('other')->setBaseUrl('https://other.example.test/');
        $this->em->persist($other);
        $this->em->flush();
        $owner = $this->createUser('600000001');
        $byUuid = $this->client('by-uuid', self::TG);
        $this->client('by-email', self::TG);
        $this->client('by-subid', self::TG);
        $this->client('same-email-other-panel', self::TG);
        $known = [
            $this->createActiveService($owner)->setPanel($other)->setClientUuid(strtoupper($byUuid))->setClientEmail('renamed'),
            $this->createActiveService($owner)->setPanel($this->panel)->setClientEmail('BY-EMAIL'),
            $this->createActiveService($owner)->setPanel($other)->setSubId($this->made['by-subid']['subId']),
            $this->createActiveService($owner)->setPanel($other)->setClientEmail('same-email-other-panel'),
        ];
        $this->em->flush();
        $before = array_map(fn (VpnService $s): array => $this->row('vpn_service', (int) $s->getId()), $known);

        [$code, $out] = $this->import();

        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 4, imported: 1, alreadyKnown: 3);
        self::assertSame($before, array_map(fn (VpnService $s): array => $this->row('vpn_service', (int) $s->getId()), $known), 'known services untouched');
        self::assertSame('same-email-other-panel', $this->em->getConnection()->fetchOne('SELECT client_email FROM vpn_service WHERE imported_at IS NOT NULL'));
    }

    public function testClientsWithoutAValidOwnerAreSkipped(): void
    {
        $this->client('zero', 0);
        $this->client('channel', -1001234567890);
        $this->client('text', 'someone');
        $this->client('missing', null);
        $this->client('owned', self::TG);

        [$code, $out] = $this->import();

        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 5, imported: 1, noOwner: 4);
        self::assertSame(1, $this->rows('telegram_account'));
    }

    public function testAPanelThatReportsNoTgIdAtAllIsAnError(): void
    {
        $this->client('a', null);
        $this->client('b', null);

        [$code, $out] = $this->import();

        self::assertSame(1, $code, $out);
        self::assertConsoleContains('did not return a tgId for any client', $out);
        self::assertSame(0, $this->rows('vpn_service'));
    }

    public function testAClientOnAnUnknownInboundIsSkipped(): void
    {
        $this->client('on-unknown', self::TG, [1, 3]);
        $this->client('no-inbound', self::TG, []);

        [$code, $out] = $this->import();

        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 2, imported: 0, unknownInbound: 1, invalid: 1);
        self::assertConsoleContains('run app:panel:sync-inbounds '.$this->panel->getId(), $out);
        self::assertSame(0, $this->rows('vpn_service'));
        self::assertSame(0, $this->rows('telegram_account'), 'no account for a client that was not imported');
    }

    public function testAClientOnSeveralInboundsKeepsThemAll(): void
    {
        $this->client('multi', self::TG, [1, 2]);

        [$code, $out] = $this->import();

        self::assertSame(0, $code, $out);
        $service = $this->onlyService();
        self::assertSame($this->reality->getId(), $service->getInbound()?->getId(), 'the first inbound the panel lists is the primary');
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT inbound_id, status FROM vpn_service_inbound WHERE service_id = ?', [$service->getId()]);
        self::assertSame([['inbound_id' => $this->vmess->getId(), 'status' => 'attached']], array_map(static fn (array $r): array => ['inbound_id' => (int) $r['inbound_id'], 'status' => $r['status']], $rows));
        self::assertCount(2, $service->getConfigLinks() ?? [], 'the subscription\'s links of both inbounds');
    }

    public function testTheStatusFollowsThePanel(): void
    {
        $this->client('enabled', '700000001', [1], ['expiryTime' => $this->ms('+10 days'), 'totalGB' => 10 * self::GB], self::GB);
        $this->client('disabled', '700000002', [1], ['enable' => false, 'expiryTime' => $this->ms('+10 days')]);
        $this->client('expired', '700000003', [1], ['enable' => false, 'expiryTime' => $this->ms('-2 days')]);
        $this->client('depleted', '700000004', [1], ['enable' => false, 'totalGB' => 10 * self::GB], 10 * self::GB);
        $this->client('unlimited', '700000005', [1], ['expiryTime' => 0, 'totalGB' => 0], 3 * self::GB);
        $this->client('first-use', '700000006', [1], ['expiryTime' => -30 * 86400000]);

        [$code, $out] = $this->import();

        self::assertSame(0, $code, $out);
        $rows = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT client_email, status, expires_at, traffic_limit_bytes FROM vpn_service') as $r) {
            $rows[$r['client_email']] = [$r['status'], null !== $r['expires_at'], null === $r['traffic_limit_bytes'] ? null : (int) $r['traffic_limit_bytes']];
        }
        self::assertSame([
            'enabled' => [VpnServiceStatus::ACTIVE, true, 10 * self::GB],
            'disabled' => [VpnServiceStatus::SUSPENDED, true, null],
            'expired' => [VpnServiceStatus::EXPIRED, true, null],
            'depleted' => [VpnServiceStatus::SUSPENDED, false, 10 * self::GB],
            'unlimited' => [VpnServiceStatus::ACTIVE, false, null],
            'first-use' => [VpnServiceStatus::ACTIVE, false, null],
        ], $rows);
        // The rules of the check-expiry run agree: it changes none of them.
        self::assertSame(0, self::getContainer()->get(ServiceExpiryChecker::class)->checkAll()->updated);
    }

    public function testADryRunWritesNothing(): void
    {
        $this->createUser('700000001');
        $this->client('one', '700000001');
        $this->client('two', '700000002', [1], ['expiryTime' => $this->ms('-1 day')]);
        $tables = ['vpn_service', 'vpn_service_inbound', 'telegram_account', '`user`', 'service_notification_log', '`order`', 'payment'];
        $before = array_map(fn (string $t): int => $this->rows($t), $tables);

        [$code, $out] = $this->import(['--dry-run' => true]);

        self::assertSame(0, $code, $out);
        self::assertConsoleContains('dry run (nothing written)', $out);
        self::assertConsoleContains('Would import: 2', $out);
        self::assertConsoleContains('New users to create: 1', $out);
        self::assertConsoleContains('Existing users matched: 1', $out);
        self::assertSame($before, array_map(fn (string $t): int => $this->rows($t), $tables));
    }

    public function testAPanelFailureStopsBeforeTheBatchIsWrittenAndARerunContinues(): void
    {
        foreach (['c-1', 'c-2', 'c-3', 'c-4', 'c-5'] as $i => $email) {
            $this->client($email, (string) (700000001 + $i));
        }
        // c-4 is in the second batch of two: its full row cannot be read.
        $this->fake->script('GET', 'panel/api/clients/get/c-4', Fake3xuiPanel::envelope(false, null, 'database is busy'), 10);

        [$code, $out] = $this->import(['--batch-size' => '2']);

        self::assertSame(1, $code, $out);
        self::assertConsoleContains('Reading the panel failed (panel_rejected)', $out);
        self::assertSame(['c-1', 'c-2'], $this->em->getConnection()->fetchFirstColumn('SELECT client_email FROM vpn_service ORDER BY client_email'), 'the first batch is kept, nothing of the second');
        self::assertSame(2, $this->rows('telegram_account'));

        $this->fake->clearScripts();
        [$code, $out] = $this->import(['--batch-size' => '2']);
        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 5, imported: 3, newUsers: 3, alreadyKnown: 2);
    }

    public function testAFailureInTheFirstBatchWritesNothing(): void
    {
        $this->client('c-1', '700000001');
        $this->client('c-2', '700000002');
        $this->fake->script('GET', 'panel/api/clients/subLinks/'.$this->made['c-2']['subId'], Fake3xuiPanel::envelope(false, null, 'boom'), 10);

        [$code, $out] = $this->import();

        self::assertSame(1, $code, $out);
        self::assertSame(0, $this->rows('vpn_service'));
        self::assertSame(0, $this->rows('telegram_account'));
        self::assertSame(0, $this->rows('`user`'));
    }

    public function testALegacyPanelIsRefused(): void
    {
        $this->fake->mode = 'legacy';
        $this->panel->setIntegrationMode(SanaeiIntegrationMode::LEGACY)->setUsername(Fake3xuiPanel::USERNAME)->setPlainPassword(Fake3xuiPanel::PASSWORD);
        $this->em->flush();
        $this->client('legacy-client', self::TG);

        [$code, $out] = $this->import();

        self::assertSame(1, $code, $out);
        self::assertConsoleContains('not a 3x-ui panel in modern mode', $out);
        self::assertConsoleContains('Nothing was imported', $out);
        self::assertSame([], $this->fake->requestLines(), 'the panel is not even asked');
        self::assertSame(0, $this->rows('vpn_service'));
    }

    public function testRenewalAndExtraTrafficPointAtANewPlan(): void
    {
        $this->setting('traffic_addon.price_per_gb', '1000');
        $this->client('old', self::TG, [1], ['expiryTime' => $this->ms('+5 days'), 'totalGB' => 10 * self::GB]);
        $this->import();
        $id = (int) $this->onlyService()->getId();

        foreach (['service_renew:'.$id => ServiceManagementService::NO_PLAN_RENEWAL, 'renewal_confirm:'.$id => ServiceManagementService::NO_PLAN_RENEWAL, 'service_add_traffic_order:'.$id => ServiceManagementService::NO_PLAN_ADD_TRAFFIC] as $button => $text) {
            $this->http->reset();
            $this->pressButton(self::TG, $button);
            self::assertTrue($this->sentTextContains($text), $button);
            self::assertContains('buy_service', $this->sentCallbacks(), $button.': with the buy button');
            self::assertContains('service_view:'.$id, $this->sentCallbacks(), $button.': and back');
        }
        self::assertSame(0, $this->rows('order_draft'));
        self::assertSame(0, $this->rows('`order`'));

        $this->startMiniAppClient();
        $session = $this->authenticate(self::TG);
        $page = $this->appGet('/app/view/services/'.$id.'/renew', $session);
        self::assertStringContainsString('data-new-plan', $page);
        self::assertStringContainsString('یک پلن جدید بخرید', $page);
        $page = $this->appGet('/app/view/services/'.$id.'/add-traffic', $session);
        self::assertStringContainsString('data-new-plan', $page);
        $answer = $this->appPostJson('/app/api/services/'.$id.'/renew', $session, [], self::newKey());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('renewal_new_plan', $answer['error'] ?? null);
        $answer = $this->appPostJson('/app/api/services/'.$id.'/add-traffic', $session, ['trafficGb' => 5], self::newKey());
        self::assertSame(409, $this->httpStatus());
        self::assertSame('add_traffic_new_plan', $answer['error'] ?? null);
        self::assertSame(0, $this->rows('`order`'));
    }

    public function testTheUsageSyncKeepsAnImportedServiceInStep(): void
    {
        $this->client('synced', self::TG, [1], ['totalGB' => 20 * self::GB, 'expiryTime' => $this->ms('+15 days')], 2 * self::GB);
        $this->import();
        $before = $this->row('vpn_service', (int) $this->onlyService()->getId());

        $sync = self::getContainer()->get(ServiceUsageSyncService::class);
        self::assertSame(1, $sync->syncActiveServices()->updated);
        $after = $this->row('vpn_service', (int) $before['id']);
        foreach (['traffic_used_bytes', 'traffic_limit_bytes', 'traffic_used_gb', 'traffic_limit_gb', 'status', 'expires_at'] as $column) {
            self::assertSame($before[$column], $after[$column], $column.': the import stored what the sync reads');
        }

        $this->fake->useTraffic('synced', 3 * self::GB);
        $this->em->clear();
        $sync->syncActiveServices();
        self::assertSame(5 * self::GB, (int) $this->row('vpn_service', (int) $before['id'])['traffic_used_bytes']);
    }

    public function testImportedServicesAreNotSales(): void
    {
        $this->client('a', '700000001', [1], ['totalGB' => 50 * self::GB, 'expiryTime' => $this->ms('+30 days')]);
        $this->client('b', '700000002', [1, 2]);

        $this->import();

        self::assertSame(2, $this->rows('vpn_service'));
        $this->assertNothingSold();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service WHERE order_id IS NOT NULL OR sold_by_reseller_id IS NOT NULL'));
    }

    public function testTheNextNotificationRunDoesNotMessageImportedCustomersAtOnce(): void
    {
        $this->client('expired', '700000001', [1], ['enable' => false, 'expiryTime' => $this->ms('-3 days')]);
        $this->client('expiring', '700000002', [1], ['expiryTime' => $this->ms('+2 days')]);
        $this->client('low', '700000003', [1], ['totalGB' => 10 * self::GB, 'expiryTime' => $this->ms('+30 days')], (int) (9.6 * self::GB));
        $this->client('fine', '700000004', [1], ['totalGB' => 10 * self::GB, 'expiryTime' => $this->ms('+30 days')], self::GB);

        [, $out] = $this->import();
        $this->assertCounts($out, read: 4, imported: 4);
        self::assertSame(0, count($this->http->telegramTexts()), 'the import messages nobody');

        $notifications = self::getContainer()->get(ServiceNotificationService::class);
        $sent = $notifications->sendExpiryWarningsWithOptions(false, 100)->sent + $notifications->sendTrafficWarningsWithOptions(false, 100)->sent + $notifications->sendExpiredNotificationsWithOptions(false, 100)->sent;
        self::assertSame(0, $sent);
        self::assertSame(0, self::getContainer()->get(ServiceExpiryChecker::class)->checkAll()->updated);
        self::assertSame(0, count($this->http->telegramTexts()));

        // A threshold reached after the import still notifies.
        $this->em->clear();
        $expiring = $this->em->getRepository(VpnService::class)->findOneBy(['clientEmail' => 'expiring']);
        self::assertInstanceOf(VpnService::class, $expiring);
        $expiring->setExpiresAt(new \DateTimeImmutable('+12 hours'));
        $this->em->flush();
        self::assertSame(1, $notifications->sendExpiryWarningsWithOptions(false, 100)->sent);
    }

    public function testABroadcastToEveryoneReachesTheImportedAccounts(): void
    {
        $this->createUser('600000001');
        $this->client('a', '700000001');
        $this->client('b', '700000002');
        $this->import();

        $broadcast = (new BroadcastMessage())->setTitle('back')->setBody('We are back')->setTargetType(BroadcastMessage::TARGET_ALL);
        $this->em->persist($broadcast);
        $this->em->flush();
        try {
            self::getContainer()->get(BroadcastSender::class)->prepareDeliveries($broadcast);
            $chats = $this->em->getConnection()->fetchFirstColumn('SELECT chat_id FROM broadcast_delivery WHERE broadcast_id = ? ORDER BY chat_id', [$broadcast->getId()]);
            self::assertSame(['600000001', '700000001', '700000002'], $chats);
        } finally {
            $this->em->getConnection()->executeStatement('DELETE FROM broadcast_delivery WHERE broadcast_id = ?', [$broadcast->getId()]);
            $this->em->getConnection()->executeStatement('DELETE FROM broadcast_message WHERE id = ?', [$broadcast->getId()]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function ignoreArgs(): iterable
    {
        yield 'arguments in traces' => ['0'];
        yield 'no arguments' => ['1'];
    }

    #[DataProvider('ignoreArgs')]
    public function testNoIdentityIsPrintedOrLogged(string $ignoreArgs): void
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', $ignoreArgs);
        try {
            $this->client('ali-old-customer', self::TG, [1, 2], ['expiryTime' => $this->ms('-1 day')]);
            $this->client('sara-old-customer', '700000002');
            $this->client('reza-old-customer', '700000003');
            $leak = fn (string $email): string => sprintf('client %s (%s, sub %s, tg %s) at %s/secret/panel/api/clients/get/%s token %s', $email, $this->made[$email]['uuid'], $this->made[$email]['subId'], self::TG, Fake3xuiPanel::ORIGIN, $email, Fake3xuiPanel::TOKEN);
            // The third client's read first fails with the panel quoting it back, then throws in the transport.
            $this->fake->script('GET', 'panel/api/clients/get/reza-old-customer', Fake3xuiPanel::envelope(false, null, $leak('reza-old-customer')));
            $logger = self::getContainer()->get(RecordingLogger::class);
            $logs = count($logger->records);

            $first = '';
            $log = $this->captureErrorLog(function () use (&$first): void {
                [$code, $first] = $this->import(['--batch-size' => '2']);
                self::assertSame(1, $code, $first);
            });
            $this->fake->script('GET', 'panel/api/clients/get/reza-old-customer', static function () use ($leak): MockResponse {
                throw new \RuntimeException($leak('reza-old-customer'));
            }, 10);
            $second = '';
            $log .= $this->captureErrorLog(function () use (&$second): void {
                [, $second] = $this->import(['--batch-size' => '2']);
            });
            $this->fake->clearScripts();
            $third = '';
            $log .= $this->captureErrorLog(function () use (&$third): void {
                [$code, $third] = $this->import(['--dry-run' => true]);
                self::assertSame(0, $code, $third);
            });
            self::assertSame(2, $this->rows('vpn_service'), 'the test proves something: two were imported, one failed');
            self::assertConsoleContains('Would import: 1', $third);

            $secrets = [];
            foreach ($this->em->getConnection()->fetchAllAssociative('SELECT client_uuid, client_email, sub_id, subscription_url, config_links, remote_id, public_token FROM vpn_service') as $row) {
                $secrets += ['uuid '.$row['client_uuid'] => $row['client_uuid'], 'email '.$row['client_email'] => $row['client_email'], 'subId '.$row['sub_id'] => $row['sub_id'],
                    'subscription url' => $row['subscription_url'], 'remote id' => $row['remote_id'], 'page token' => $row['public_token']];
                foreach (json_decode((string) $row['config_links'], true) as $i => $link) {
                    $secrets['link '.$i.' '.$row['client_email']] = $link;
                }
            }
            foreach ($this->made as $email => $identity) {
                $secrets += ['email '.$email => $email, 'uuid '.$email => $identity['uuid'], 'subId '.$email => $identity['subId']];
            }
            $secrets += ['telegram id' => self::TG, 'second telegram id' => '700000002', 'token' => Fake3xuiPanel::TOKEN];
            $texts = ['output 1' => $first, 'output 2' => $second, 'output 3' => $third, 'error log' => $log, 'logs' => implode("\n", array_map(
                static fn (array $r): string => $r['message'].' '.json_encode($r['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                array_slice($logger->records, $logs),
            ))];
            foreach ($texts as $where => $text) {
                self::assertContainsNoSecret($text, $secrets, $where);
                self::assertStringNotContainsString('/secret/', $text, $where);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $before);
        }
    }

    public function testTheOutputReadsAtSixtyColumns(): void
    {
        $this->client('a', self::TG);
        $this->client('b', '0');
        $this->client('c', '700000002', [3]);
        putenv('COLUMNS=60');
        $_SERVER['COLUMNS'] = $_ENV['COLUMNS'] = '60';

        [$code, $out] = $this->import();

        self::assertSame(0, $code, $out);
        $this->assertCounts($out, read: 3, imported: 1, newUsers: 1, noOwner: 1, unknownInbound: 1);
        self::assertConsoleContains('run app:panel:sync-inbounds '.$this->panel->getId().', then run this import again.', $out);
        foreach (explode("\n", $out) as $line) {
            self::assertLessThanOrEqual(60, mb_strlen(rtrim($line)), $line);
        }
    }

    public function testManyServicesForOneOwnerAreCountedAndWarnedAbout(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->client('admin-made-'.$i, self::TG);
        }
        $this->client('customer', '700000002');
        $this->client('customer-2', '700000002');

        foreach ([60 => ['--dry-run' => true], 200 => []] as $columns => $options) {
            putenv('COLUMNS='.$columns);
            $_SERVER['COLUMNS'] = $_ENV['COLUMNS'] = (string) $columns;
            [$code, $out] = $this->import($options);

            self::assertSame(0, $code, $out);
            self::assertConsoleContains('Most services for one owner: 21', $out);
            self::assertConsoleContains('One Telegram account would own 21 services: check that the panel\'s tgId values are the customers\' and not an admin\'s.', $out);
            self::assertConsoleNotContains(self::TG, $out);
            if (60 === $columns) {
                foreach (explode("\n", $out) as $line) {
                    self::assertLessThanOrEqual(60, mb_strlen(rtrim($line)), $line);
                }
            }
        }
    }

    public function testTwentyServicesForOneOwnerAreNotWarnedAbout(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->client('family-'.$i, self::TG);
        }

        [$code, $out] = $this->import();

        self::assertSame(0, $code, $out);
        self::assertConsoleContains('Most services for one owner: 20', $out);
        self::assertConsoleNotContains('would own', $out);
    }

    /**
     * @param list<int>                 $inboundIds
     * @param array<string, mixed>      $fields
     * @param int|string|null           $tgId null: the row has no tgId field
     */
    private function client(string $email, int|string|null $tgId, array $inboundIds = [1], array $fields = [], int $usedBytes = 0): string
    {
        $uuid = Uuid::v4()->toRfc4122();
        $subId = bin2hex(random_bytes(8));
        $this->made[$email] = ['uuid' => $uuid, 'subId' => $subId];
        $row = array_merge([
            'email' => $email, 'id' => $uuid, 'subId' => $subId, 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0,
            'tgId' => $tgId, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0, 'inboundIds' => $inboundIds, 'dbId' => count($this->fake->clients) + 1,
        ], $fields);
        if (null === $tgId) {
            unset($row['tgId']);
        }
        $this->fake->clients[$email] = $row;
        if ($usedBytes > 0) {
            $this->fake->traffic[$email] = ['up' => intdiv($usedBytes, 4), 'down' => $usedBytes - intdiv($usedBytes, 4), 'lastOnline' => 0];
        }

        return $uuid;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{int, string}
     */
    private function import(array $options = []): array
    {
        $this->em->clear();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:panel:import-clients'));
        $code = $tester->execute(['--panel' => (string) $this->panel->getId()] + $options);

        return [$code, $tester->getDisplay()];
    }

    private function assertCounts(string $out, int $read, int $imported, ?int $newUsers = null, ?int $existingUsers = null, int $alreadyKnown = 0, int $noOwner = 0, int $unknownInbound = 0, int $invalid = 0): void
    {
        self::assertConsoleContains('Clients read: '.$read, $out);
        self::assertConsoleContains('Imported: '.$imported, $out);
        if (null !== $newUsers) {
            self::assertConsoleContains('New users created: '.$newUsers, $out);
        }
        if (null !== $existingUsers) {
            self::assertConsoleContains('Existing users matched: '.$existingUsers, $out);
        }
        self::assertConsoleContains('Already known: '.$alreadyKnown, $out);
        self::assertConsoleContains('No owner (no tgId): '.$noOwner, $out);
        self::assertConsoleContains('Unknown inbound: '.$unknownInbound, $out);
        self::assertConsoleContains('Invalid: '.$invalid, $out);
        self::assertConsoleContains('Most services for one owner: ', $out);
    }

    private function assertNothingSold(): void
    {
        foreach (['`order`', 'payment', 'order_draft', 'wallet', 'wallet_transaction', 'discount_usage', 'reseller_customer_service'] as $table) {
            self::assertSame(0, $this->rows($table), $table);
        }
    }

    private function onlyService(): VpnService
    {
        $this->em->clear();
        $services = $this->em->getRepository(VpnService::class)->findAll();
        self::assertCount(1, $services);

        return $services[0];
    }

    private function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function ms(string $relative): int
    {
        return (new \DateTimeImmutable($relative))->getTimestamp() * 1000;
    }

    private function setting(string $key, string $value): void
    {
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['keyName' => $key]) ?? (new Setting())->setKeyName($key)->setType('string');
        $setting->setValue($value);
        $this->em->persist($setting);
        $this->em->flush();
    }
}
