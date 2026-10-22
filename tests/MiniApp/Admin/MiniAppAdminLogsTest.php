<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\MiniApp\Admin\AdminLists;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Logs in the Mini App admin (LOGS_VIEW, super admin only), read only: the audit log filtered by admin, action, source
 * and date, the notification log and the bot message log, paged and searchable; payloads shown through
 * AdminJsonFormatter, secrets masked. No export, nothing to change.
 */
final class MiniAppAdminLogsTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM bot_message_log');
        $connection->executeStatement('DELETE FROM service_notification_log');
        $audit = static fn (string $actor, string $source, string $action, string $at, ?array $details = null) => $connection->insert('admin_audit_log', [
            'actor_admin_user_id' => null, 'actor_label' => $actor, 'source' => $source, 'action' => $action, 'target_type' => 'Payment', 'target_id' => '77',
            'details' => null === $details ? null : json_encode($details), 'created_at' => $at,
        ]);
        $audit('admin@example.test', 'web', 'payment.approve', '2026-10-01 10:00:00');
        $audit('admin@example.test', 'bot', 'payment.reject', '2026-10-05 10:00:00');
        $audit('TELEGRAM_ADMIN_CHAT_ID', 'miniapp', 'settings.update', '2026-10-10 10:00:00', ['page' => 'automation', 'api_token' => 'audit-secret-token']);
        $connection->insert('bot_message_log', ['telegram_id' => self::BUYER, 'direction' => 'incoming', 'update_type' => 'message', 'payload' => json_encode(['text' => 'سلام', 'password' => 'msg-secret-pass']), 'created_at' => '2026-10-11 09:00:00']);
        $connection->insert('bot_message_log', ['telegram_id' => '555000', 'direction' => 'outgoing', 'update_type' => 'message', 'payload' => json_encode(['text' => 'hi']), 'created_at' => '2026-10-11 09:01:00']);
        $connection->insert('service_notification_log', ['service_id' => $this->service->getId(), 'user_id' => $this->buyer->getId(), 'type' => 'expiry', 'key_name' => 'expiry_3d', 'sent_at' => '2026-10-12 08:00:00', 'payload' => json_encode(['days' => 3, 'secret' => 'notify-secret'])]);
        $this->startMiniAppClient();
    }

    public function testTheAuditLogIsFilteredByAdminActionSourceAndDate(): void
    {
        $session = $this->authenticate(self::SUPER);
        $actions = fn (string $query): array => (new Crawler($this->appGet('/app/admin/logs'.$query, $session)))->filter('[data-log-entry] .action')->each(static fn (Crawler $c): string => trim($c->text()));

        self::assertSame(['settings.update', 'payment.reject', 'payment.approve'], $actions(''), 'newest first');
        self::assertSame(['payment.reject'], $actions('?source=bot'));
        self::assertSame(['settings.update'], $actions('?source=miniapp'));
        self::assertSame(['payment.reject', 'payment.approve'], $actions('?admin=admin%40example.test'));
        self::assertSame(['payment.approve'], $actions('?action=payment.approve'));
        self::assertSame(['payment.reject'], $actions('?from=2026-10-02&to=2026-10-06'));
        self::assertSame(['settings.update'], $actions('?q=settings'));
        self::assertSame(['settings.update', 'payment.reject', 'payment.approve'], $actions('?source=everything&from=yesterday'), 'unknown filter values are ignored');

        $filters = new Crawler($this->appGet('/app/admin/logs', $session));
        self::assertSame(['', 'web', 'bot', 'cli', 'miniapp'], $filters->filter('select[name="source"] option')->each(static fn (Crawler $c): string => (string) $c->attr('value')));
        self::assertContains('admin@example.test', $filters->filter('select[name="admin"] option')->each(static fn (Crawler $c): string => (string) $c->attr('value')));
    }

    public function testPayloadsAreShownMaskedAndNothingIsExportedOrChanged(): void
    {
        $session = $this->authenticate(self::SUPER);
        // Each tab filtered to an entry that carries a secret key.
        foreach (['audit' => '&source=miniapp', 'messages' => '&direction=incoming', 'notifications' => ''] as $tab => $filter) {
            foreach (['', '&lang=en'] as $lang) {
                $list = $this->appGet('/app/admin/logs?tab='.$tab.$filter.$lang, $session);
                self::assertSame(200, $this->httpStatus(), $tab);
                self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($list), $tab);
            }
            $page = new Crawler($list);
            self::assertCount(0, $page->filter('form[method="post"], [data-action], a[href*="export"], a[download]'), $tab.': read only, no export');
            $entry = $page->filter('[data-log-entry]')->first()->filter('a')->attr('href');
            $detail = $this->appGet((string) $entry, $session);
            self::assertSame(200, $this->httpStatus(), (string) $entry);
            self::assertStringContainsString('••••••', $detail, $tab.': the secret key is masked');
            foreach (['audit-secret-token', 'msg-secret-pass', 'notify-secret'] as $secret) {
                self::assertStringNotContainsString($secret, $detail);
                self::assertStringNotContainsString($secret, $list);
            }
            self::assertStringContainsString('<pre class="admin-json">', $detail, 'AdminJsonFormatter\'s escaped display');
        }
    }

    public function testTheBotMessageAndNotificationLogsAreSearchable(): void
    {
        $session = $this->authenticate(self::SUPER);
        $ids = fn (string $query): int => (new Crawler($this->appGet('/app/admin/logs?tab=messages'.$query, $session)))->filter('[data-log-entry]')->count();
        self::assertSame(2, $ids(''));
        self::assertSame(1, $ids('&q='.self::BUYER));
        self::assertSame(1, $ids('&direction=outgoing'));
        $notifications = new Crawler($this->appGet('/app/admin/logs?tab=notifications&q=expiry_3d', $session));
        self::assertSame(1, $notifications->filter('[data-log-entry]')->count());
        self::assertSame(0, (new Crawler($this->appGet('/app/admin/logs?tab=notifications&q=nothing', $session)))->filter('[data-log-entry]')->count());
    }

    public function testTheLogIsPaged(): void
    {
        $connection = $this->em->getConnection();
        for ($i = 0; $i < AdminLists::PAGE_SIZE + 2; ++$i) {
            $connection->insert('admin_audit_log', ['actor_label' => 'bulk', 'source' => 'cli', 'action' => 'bulk.'.$i, 'created_at' => '2026-09-01 10:00:00']);
        }
        $session = $this->authenticate(self::SUPER);
        $first = new Crawler($this->appGet('/app/admin/logs?source=cli', $session));
        self::assertCount(AdminLists::PAGE_SIZE, $first->filter('[data-log-entry]'));
        self::assertCount(1, $first->filter('[data-next-page]'));
        self::assertStringContainsString('source=cli', (string) $first->filter('[data-next-page]')->attr('href'), 'the filters are kept');
        self::assertCount(2, (new Crawler($this->appGet('/app/admin/logs?source=cli&page=1', $session)))->filter('[data-log-entry]'));
    }

    public function testOnlyTheSuperAdminSeesLogs(): void
    {
        foreach ([self::ADMIN, self::SUPPORT] as $who) {
            $session = $this->authenticate($who);
            $this->appGet('/app/admin/logs', $session);
            self::assertSame(403, $this->httpStatus());
        }
    }
}
