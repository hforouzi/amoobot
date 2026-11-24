<?php

declare(strict_types=1);

namespace App\Tests\Admin\Health;

use App\Admin\Application\Health\SystemHealth;
use App\Admin\Application\Health\TelegramWebhookStatus;
use App\Admin\UI\Shell\AdminUiMode;
use App\Entity\AdminUser;
use App\Payment\Application\GatewayFailureMemory;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * AD3 (docs/design/admin-redesign.md 4.3, 4.4, decision 13): the health block and version chip of the modern dashboard,
 * the jobs page under Settings and the webhook's "check now".
 *
 * - A page load never calls Telegram (the MockHttpResponder records every outgoing request: the spy).
 * - "check now" is a GET confirm page; only a POST with its CSRF token calls getWebhookInfo, once.
 * - Only SETTINGS_MANAGE sees any of it; the pages are modern only (404 in classic) and classic shows nothing new.
 * - PHP reads only the job files and the two one-line files; no code asks systemd, sudo, the backups or the deploy record.
 */
final class SystemHealthTest extends IntegrationTestCase
{
    private const NOW_COMMIT = '0123456789ab';

    private string $state;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->state = sys_get_temp_dir().'/ad3-state-'.bin2hex(random_bytes(6));
        mkdir($this->state.'/jobs', 0755, true);
        // Read when the services are first built, after the kernel boots (SystemStateFiles' env override).
        $_SERVER['AMOOBOT_STATE_DIR'] = $_ENV['AMOOBOT_STATE_DIR'] = $this->state;
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $cache = self::getContainer()->get('cache.app');
        $cache->delete(TelegramWebhookStatus::CACHE_KEY);
        $cache->delete(GatewayFailureMemory::CACHE_KEY);
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [AdminUiMode::SETTING]);
        unset($_SERVER['AMOOBOT_STATE_DIR'], $_ENV['AMOOBOT_STATE_DIR']);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->state, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->state);
        parent::tearDown();
    }

    private function health(): SystemHealth
    {
        return self::getContainer()->get(SystemHealth::class);
    }

    private function serverFiles(): void
    {
        file_put_contents($this->state.'/scheduled-jobs.conf', "sync 5700 *:25/30\norders 900 *:0/5\n");
        touch($this->state.'/jobs/sync.last-success');
        touch($this->state.'/jobs/orders.last-success');
        file_put_contents($this->state.'/deployed-version', self::NOW_COMMIT.' '.gmdate('Y-m-d\TH:i:s\Z').' main'."\n");
        file_put_contents($this->state.'/backup-last-success', gmdate('Y-m-d\TH:i:s\Z', time() - 3600)."\n");
    }

    private function layout(string $mode): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM setting WHERE key_name = ?', [AdminUiMode::SETTING]);
        $connection->executeStatement("INSERT INTO setting (key_name, value, type, created_at) VALUES (?, ?, 'string', NOW())", [AdminUiMode::SETTING, $mode]);
        self::getContainer()->get(AdminUiMode::class)->reset();
    }

    private function login(string $role): void
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-'.bin2hex(random_bytes(3)).'@ad3.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
    }

    private function statusOf(string $method, string $url): int
    {
        $this->client->request($method, $url);

        return $this->client->getResponse()->getStatusCode();
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** @return list<array{method: string, url: string, options: array<string, mixed>}> */
    private function webhookCalls(): array
    {
        return $this->http->requests('/getWebhookInfo');
    }

    public function testVersionAndBackupLinesFromTheOneLineFiles(): void
    {
        self::assertSame(['state' => 'dev', 'commit' => null, 'deployedAt' => null, 'ref' => null], $this->health()->version(), 'no file: a development install');

        $this->serverFiles();
        $summary = $this->health()->summary();
        self::assertSame('ok', $summary['version']['state']);
        self::assertSame(self::NOW_COMMIT, $summary['version']['commit']);
        self::assertSame('main', $summary['version']['ref']);
        self::assertSame('ok', $summary['backup']['state']);
        self::assertSame('ok', $summary['jobs']['state']);
        self::assertSame('ok', $summary['gateways']['state']);
        self::assertSame('unchecked', $summary['webhook']['state']);
        self::assertSame(0, $summary['attention'], 'an unchecked webhook is not counted');

        file_put_contents($this->state.'/backup-last-success', gmdate('Y-m-d\TH:i:s\Z', time() - SystemHealth::BACKUP_MAX_AGE - 60)."\n");
        file_put_contents($this->state.'/deployed-version', "garbage; rm -rf /\n");
        $summary = $this->health()->summary();
        self::assertSame(['state' => 'problem', 'reason' => 'stale'], array_intersect_key($summary['backup'], ['state' => 1, 'reason' => 1]));
        self::assertSame('unknown', $summary['version']['state']);
        self::assertSame(1, $summary['attention']);

        // Unreadable (a directory where the file should be) and missing: unknown, never an error.
        unlink($this->state.'/backup-last-success');
        mkdir($this->state.'/backup-last-success');
        self::assertSame(['state' => 'unknown', 'at' => null, 'reason' => 'unreadable'], $this->health()->summary()['backup']);
        rmdir($this->state.'/backup-last-success');
        self::assertSame(['state' => 'unknown', 'at' => null, 'reason' => 'none'], $this->health()->summary()['backup']);
    }

    public function testGatewayFailuresAreRememberedForADay(): void
    {
        $memory = self::getContainer()->get(GatewayFailureMemory::class);
        $memory->record(7, 'Tetra', 'unauthorized');
        $memory->record(9, 'Other', 'store_disabled');
        $memory->record(7, 'Tetra', 'store_disabled');

        $failures = $memory->recent();
        self::assertSame([7, 9], array_keys($failures), 'one entry per gateway, newest first');
        self::assertSame('store_disabled', $failures[7]['code']);
        $summary = $this->health()->summary();
        self::assertSame('problem', $summary['gateways']['state']);
        self::assertCount(2, $summary['gateways']['failures']);

        // Older than 24 h: gone.
        self::getContainer()->get('cache.app')->delete(GatewayFailureMemory::CACHE_KEY);
        self::getContainer()->get('cache.app')->get(GatewayFailureMemory::CACHE_KEY, static fn (): array => [3 => ['gatewayId' => 3, 'title' => 'Old', 'code' => 'unauthorized', 'at' => time() - GatewayFailureMemory::TTL - 1]]);
        self::assertSame([], $memory->recent());
    }

    public function testThePageLoadsNeverCallTelegramAndOnlyThePostWithItsTokenDoes(): void
    {
        $this->serverFiles();
        $this->layout('modern');
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        $this->http->onJson('/getWebhookInfo', ['ok' => true, 'result' => ['url' => 'https://bot.example.test/telegram/webhook/SECRETPATH', 'pending_update_count' => 3, 'last_error_date' => time() - 30, 'last_error_message' => 'Wrong response from the webhook: 503 Service Unavailable']]);

        $crawler = $this->client->request('GET', '/admin');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-health-chip]'));
        self::assertStringContainsString(self::NOW_COMMIT, $crawler->filter('[data-health-chip]')->text());
        self::assertCount(1, $crawler->filter('#system-health [data-health="webhook"]'));
        self::assertCount(1, $crawler->filter('#system-health [data-health="jobs"].ad3-ok'));

        self::assertSame(200, $this->statusOf('GET', '/admin/settings/jobs'));
        self::assertStringContainsString('data-job="sync"', $this->html());

        $confirm = $this->client->request('GET', '/admin/settings/jobs/webhook-check');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->webhookCalls(), 'the dashboard, the jobs page and the confirm page never call Telegram');

        // No token, a wrong token: refused, still no call.
        $this->client->request('POST', '/admin/settings/jobs/webhook-check');
        self::assertTrue($this->client->getResponse()->isRedirect('/admin/settings/jobs'), (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->request('POST', '/admin/settings/jobs/webhook-check', ['_token' => 'forged']);
        self::assertTrue($this->client->getResponse()->isRedirect('/admin/settings/jobs'), (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([], $this->webhookCalls());

        $form = $confirm->filter('[data-action-confirm] form')->form();
        $this->client->submit($form);
        self::assertTrue($this->client->getResponse()->isRedirect('/admin/settings/jobs'), (string) $this->client->getResponse()->headers->get('Location'));
        self::assertCount(1, $this->webhookCalls(), 'only the POST with its token asks Telegram, once');

        $crawler = $this->client->request('GET', '/admin');
        $line = $crawler->filter('#system-health [data-health="webhook"]');
        self::assertStringContainsString('ad3-problem', (string) $line->attr('class'), 'a delivery error 30 s before the check');
        self::assertStringContainsString('503 Service Unavailable', $line->text());
        self::assertStringNotContainsString('SECRETPATH', $this->html(), 'the webhook URL (its secret) is never kept or shown');
        self::assertCount(1, $this->webhookCalls(), 'the cached answer is shown without a new call');
    }

    public function testCheckNowAcceptsNoOtherMethod(): void
    {
        $this->layout('modern');
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        self::assertSame(405, $this->statusOf('PUT', '/admin/settings/jobs/webhook-check'));
        self::assertSame(405, $this->statusOf('POST', '/admin/settings/jobs'));
        self::assertSame([], $this->webhookCalls());
    }

    public function testOnlySettingsManagersSeeHealthAndOnlyInModern(): void
    {
        $this->serverFiles();
        $this->layout('modern');
        foreach ([AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT] as $role) {
            $this->login($role);
            $crawler = $this->client->request('GET', '/admin');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertCount(0, $crawler->filter('[data-health-chip], #system-health'), $role);
            self::assertSame(403, $this->statusOf('GET', '/admin/settings/jobs'), $role);
            self::assertSame(403, $this->statusOf('POST', '/admin/settings/jobs/webhook-check'), $role);
        }

        $this->layout('classic');
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        self::assertSame(200, $this->statusOf('GET', '/admin'));
        self::assertStringNotContainsString('data-health-chip', $this->html());
        self::assertStringNotContainsString('system-health', $this->html());
        foreach (['GET' => '/admin/settings/jobs', 'POST' => '/admin/settings/jobs/webhook-check'] as $method => $url) {
            $this->client->request($method, $url);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'classic: '.$url);
        }
        self::assertSame([], $this->webhookCalls());
    }

    public function testUnreadableOrMissingServerFilesShowUnknownNotAnError(): void
    {
        $this->layout('modern');
        $this->login(AdminUser::ROLE_SUPER_ADMIN);
        mkdir($this->state.'/scheduled-jobs.conf');
        $crawler = $this->client->request('GET', '/admin');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('#system-health [data-health="jobs"].ad3-unknown'));
        self::assertCount(1, $crawler->filter('#system-health [data-health="backup"].ad3-unknown'));
        self::assertStringContainsString('dev', $crawler->filter('[data-health-chip]')->text());
        self::assertSame(200, $this->statusOf('GET', '/admin/settings/jobs'));
    }

    /**
     * Least privilege (4.4): the web side (src/Admin, src/Payment) never asks systemd or sudo, and never names the
     * backups or the deploy record, in code (comments may explain why).
     */
    public function testNoPhpCodeReachesForMoreThanTheTwoPublicFiles(): void
    {
        $offending = [];
        $root = dirname(__DIR__, 3);
        foreach (['src/Admin', 'src/Payment'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ('php' !== $file->getExtension()) {
                    continue;
                }
                $code = '';
                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                    $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
                }
                foreach (['deployed-commit', '/var/backups', 'releases.log', 'systemctl', 'sudo '] as $needle) {
                    if (str_contains($code, $needle)) {
                        $offending[] = $file->getFilename().': '.$needle;
                    }
                }
            }
        }
        self::assertSame([], $offending);

        $health = (string) file_get_contents($root.'/src/Admin/Application/Health/ScheduledJobHealth.php').(string) file_get_contents($root.'/src/Admin/Application/Health/SystemStateFiles.php');
        foreach (['exec(', 'shell_exec', 'proc_open', 'passthru', 'popen', 'system('] as $call) {
            self::assertStringNotContainsString($call, $health);
        }
    }
}
