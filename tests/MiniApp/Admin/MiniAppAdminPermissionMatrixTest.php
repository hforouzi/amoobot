<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\MiniApp\Admin\AdminRoutePermissions;
use App\Security\AdminAccessPolicy;
use App\Security\AdminPermission;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every Mini App admin route, for every kind of user (TELEGRAM_ADMIN_CHAT_ID super admin, linked ADMIN and SUPPORT,
 * a plain customer, a deactivated admin, no session): allowed exactly when AdminRolePermissions grants the route's
 * permission, refused with 403 (401 without a session) otherwise, and no response carries a secret.
 */
final class MiniAppAdminPermissionMatrixTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    private const SECRETS = ['panel-secret.example', 'panel-pass-123', 'gw-api-key-456', 'AgAD-receipt', 'dummy_existing'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $panel = $this->service->getPanel();
        self::assertNotNull($panel);
        $panel->setBaseUrl('https://panel-secret.example:2053/x')->setUsername('panel-pass-123')->setSubscriptionBaseUrl('https://panel-secret.example:2096/sub');
        $this->payment->getGateway()?->setConfig(['api_key' => 'gw-api-key-456']);
        $this->em->flush();
        $this->startMiniAppClient();
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
        ];
        $path = $route->getPath();
        foreach ($ids as $segment => $id) {
            if (str_contains($path, $segment.'{id}')) {
                return str_replace('{id}', (string) $id, $path);
            }
        }

        return $path;
    }
}
