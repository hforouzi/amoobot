<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\AppDownload;
use App\Entity\BroadcastMessage;
use App\Entity\ClientApp;
use App\Entity\DiscountCode;
use App\Entity\RequiredChannel;
use App\Entity\TrialPlan;
use App\MiniApp\Admin\AdminRoutePermissions;
use App\Security\AdminAccessPolicy;
use App\Security\AdminPermission;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\RestoresTables;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every Mini App admin route, for every kind of user (TELEGRAM_ADMIN_CHAT_ID super admin, linked ADMIN and SUPPORT,
 * a plain customer, a deactivated admin, no session): allowed exactly when AdminRolePermissions grants the route's
 * permission, refused with 403 (401 without a session) otherwise, and no response carries a secret.
 */
final class MiniAppAdminPermissionMatrixTest extends IntegrationTestCase
{
    private TrialPlan $trialPlan;
    private DiscountCode $discount;
    private BroadcastMessage $broadcast;
    private RequiredChannel $channel;
    private ClientApp $clientApp;
    private AppDownload $download;
    private int $auditEntry;

    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;
    use RestoresTables;

    /** The panel's address and login, a gateway key, a receipt file id, a panel remote id, the bot token. */
    private const SECRETS = ['panel-secret.example', 'panel-pass-123', 'gw-api-key-456', 'AgAD-receipt', 'dummy_existing', 'test-only-fake-token'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $panel = $this->service->getPanel();
        self::assertNotNull($panel);
        $panel->setBaseUrl('https://panel-secret.example:2053/x')->setUsername('panel-pass-123')->setSubscriptionBaseUrl('https://panel-secret.example:2096/sub');
        $this->payment->getGateway()?->setConfig(['api_key' => 'gw-api-key-456']);
        // The catalog records the M3b routes act on.
        $this->trialPlan = (new TrialPlan())->setTitle('Matrix trial')->setInbound($this->service->getInbound());
        $this->discount = (new DiscountCode())->setCode('MATRIX10')->setType(DiscountCode::TYPE_PERCENT)->setValue(10);
        $this->em->persist($this->trialPlan);
        $this->em->persist($this->discount);
        // The M3c records.
        $this->em->getConnection()->executeStatement('DELETE FROM broadcast_delivery');
        $this->em->getConnection()->executeStatement('DELETE FROM broadcast_message');
        $this->broadcast = (new BroadcastMessage())->setTitle('Matrix broadcast')->setBody('Hello');
        $this->em->persist($this->broadcast);
        $this->em->getConnection()->executeStatement('DELETE FROM required_channel');
        $this->channel = (new RequiredChannel())->setTitle('Matrix channel')->setChatId('@matrix_channel');
        $this->em->persist($this->channel);
        $this->snapshotTables('client_app', 'app_download');
        $this->clientApp = (new ClientApp())->setName('Matrix app')->setPlatforms(['android'])->setImportUrlTemplate('hiddify://import/{url}');
        $this->download = (new AppDownload())->setTitle('Matrix link')->setPlatform('android')->setType(AppDownload::TYPE_URL)->setExternalUrl('https://dl.example.com/a.apk');
        $this->em->persist($this->clientApp);
        $this->em->persist($this->download);
        $this->em->getConnection()->insert('admin_audit_log', ['actor_label' => 'matrix', 'source' => 'cli', 'action' => 'matrix.entry', 'details' => json_encode(['api_key' => 'gw-api-key-456']), 'created_at' => '2026-10-01 10:00:00']);
        $this->auditEntry = (int) $this->em->getConnection()->lastInsertId();
        $this->em->flush();
        $this->startMiniAppClient();
    }

    protected function tearDown(): void
    {
        $this->restoreTables();
        parent::tearDown();
    }

    public function testEveryRouteForEveryRole(): void
    {
        $policy = self::getContainer()->get(AdminAccessPolicy::class);
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $users = [self::SUPER, self::ADMIN, self::SUPPORT, self::CUSTOMER_ONLY, self::INACTIVE];
        $sessions = [];
        foreach ($users as $who) {
            $sessions[$who] = $this->authenticate($who);
        }

        $checked = 0;
        foreach (AdminRoutePermissions::routes() as $name => $permission) {
            $route = $routes->get($name);
            self::assertInstanceOf(Route::class, $route);
            $path = $this->pathOf($route);
            $method = in_array('POST', $route->getMethods(), true) ? 'POST' : 'GET';

            foreach ($users as $who) {
                $actor = $policy->botActor($who);
                $allowed = AdminRoutePermissions::ANY_ADMIN === $permission ? [] !== $policy->permissions($actor) : ($permission instanceof AdminPermission && $policy->can($actor, $permission));
                $body = 'POST' === $method ? json_encode($this->appPostJson($path, $sessions[$who], [], self::newKey()), JSON_THROW_ON_ERROR) : $this->appGet($path, $sessions[$who]);
                $status = $this->httpStatus();
                if ($allowed) {
                    self::assertNotContains($status, [401, 403], sprintf('%s %s for %s', $method, $name, $who));
                    self::assertLessThan(500, $status, sprintf('%s %s for %s: %s', $method, $name, $who, mb_substr($body, 0, 300)));
                } else {
                    self::assertSame(403, $status, sprintf('%s %s for %s', $method, $name, $who));
                }
                foreach (self::SECRETS as $secret) {
                    self::assertStringNotContainsString($secret, $body, sprintf('%s %s for %s leaks %s', $method, $name, $who, $secret));
                }
                ++$checked;
            }

            'POST' === $method ? $this->appPostJson($path, ['token' => str_repeat('a', 43), 'csrf' => 'x'], [], self::newKey()) : $this->appGet($path, null);
            self::assertSame(401, $this->httpStatus(), $name.' without a session');
        }
        self::assertGreaterThan(100, $checked);
    }

    /** As on the web (AdminRolePermissions): panels, settings and logs are the super admin's. */
    public function testAnAdminHasNoPanelsSettingsOrLogs(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $checked = 0;
        foreach (AdminRoutePermissions::routes() as $name => $permission) {
            if (!in_array($permission, [AdminPermission::PANELS_MANAGE, AdminPermission::SETTINGS_MANAGE, AdminPermission::LOGS_VIEW], true)) {
                continue;
            }
            $route = $routes->get($name);
            self::assertInstanceOf(Route::class, $route);
            in_array('POST', $route->getMethods(), true) ? $this->appPostJson($this->pathOf($route), $session, [], self::newKey()) : $this->appGet($this->pathOf($route), $session);
            self::assertSame(403, $this->httpStatus(), $name);
            ++$checked;
        }
        self::assertGreaterThan(15, $checked);
    }

    /** Every state-changing admin call needs the session's CSRF token, whatever the role. */
    public function testEveryAdminPostNeedsTheCsrfToken(): void
    {
        $session = $this->authenticate(self::SUPER);
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $checked = 0;
        foreach (array_keys(AdminRoutePermissions::routes()) as $name) {
            $route = $routes->get($name);
            self::assertInstanceOf(Route::class, $route);
            if (!in_array('POST', $route->getMethods(), true)) {
                continue;
            }
            $answer = $this->appPostJson($this->pathOf($route), $session, [], self::newKey(), 'wrong-csrf');
            self::assertSame(403, $this->httpStatus(), $name);
            self::assertSame('csrf', $answer['error'] ?? null, $name);
            ++$checked;
        }
        self::assertGreaterThan(60, $checked);
        self::assertSame([], array_filter($this->auditRows(), static fn (array $r): bool => 'miniapp' === $r['source']), 'nothing was done');
    }

    public function testAPermissionRemovedMidSessionClosesItsActionsAtOnce(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $suspend = '/app/api/admin/services/'.$this->service->getId().'/suspend';
        $this->appPostJson($suspend, $session, [], self::newKey());
        self::assertSame(428, $this->httpStatus(), 'ADMIN may suspend (asked to confirm)');

        $this->em->getConnection()->executeStatement('UPDATE admin_user SET roles = ? WHERE id = ?', [json_encode(['ROLE_SUPPORT']), $this->adminUser->getId()]);
        $this->appPostJson($suspend, $session, [], self::newKey());
        self::assertSame(403, $this->httpStatus(), 'now SUPPORT: refused in the same session');
        $this->appGet('/app/admin/services/'.$this->service->getId(), $session);
        self::assertSame(200, $this->httpStatus(), 'viewing is still allowed');
    }

    private function pathOf(Route $route): string
    {
        $ids = [
            '/payments/' => $this->payment->getId(),
            '/orders/' => $this->payment->getOrder()->getId(),
            '/customers/' => $this->buyer->getId(),
            '/services/' => $this->service->getId(),
            '/plans/' => $this->payment->getOrder()->getPlan()->getId(),
            '/trial-plans/' => $this->trialPlan->getId(),
            '/discounts/' => $this->discount->getId(),
            '/broadcasts/' => $this->broadcast->getId(),
            '/panels/' => $this->service->getPanel()?->getId(),
            '/channels/' => $this->channel->getId(),
            '/client-apps/' => $this->clientApp->getId(),
            '/downloads/' => $this->download->getId(),
            '/logs/audit/' => $this->auditEntry,
        ];
        $path = str_replace('{kind}', 'audit', $route->getPath());
        foreach ($ids as $segment => $id) {
            if (str_contains($path, $segment.'{id}')) {
                return str_replace('{id}', (string) $id, $path);
            }
        }

        return $path;
    }
}
