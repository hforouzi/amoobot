<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\AdminAuditLog;
use App\Entity\AdminUser;
use App\MiniApp\Admin\AdminConfirmTokens;
use App\MiniApp\Admin\AdminRouteAccess;
use App\MiniApp\Admin\AdminRoutePermissions;
use App\MiniApp\Checkout\CheckoutFailure;
use App\MiniApp\Checkout\CheckoutIdempotency;
use App\MiniApp\Security\MiniAppUser;
use App\Security\AdminAccessPolicy;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;
use App\Entity\TelegramAccount;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Mini App admin shell and its security model: a fail-closed route -> permission map, admin rights re-checked on
 * every request, a shorter admin session, the confirm tokens of money / destructive actions, idempotency outside an
 * outer transaction, and audit entries with source "miniapp".
 */
final class MiniAppAdminShellTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->startMiniAppClient();
    }

    public function testEveryAdminRouteHasAPermissionAndTheMapHasNoStaleEntries(): void
    {
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $unmapped = [];
        foreach ($routes as $name => $route) {
            if (AdminRoutePermissions::isAdminPath($route->getPath()) && null === AdminRoutePermissions::forRoute($name)) {
                $unmapped[] = $name;
            }
        }
        self::assertSame([], $unmapped, 'admin routes without a permission (they are refused to everyone)');

        foreach (array_keys(AdminRoutePermissions::routes()) as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route, $name.' is mapped but does not exist');
            self::assertTrue(AdminRoutePermissions::isAdminPath($route->getPath()), $name.' is not under the admin prefixes');
        }
    }

    public function testAnUnmappedRouteIsRefusedEvenToTheSuperAdmin(): void
    {
        $this->authenticate(self::SUPER);
        $user = $this->miniAppUserFor(self::SUPER);
        $access = self::getContainer()->get(AdminRouteAccess::class);

        self::assertTrue($access->allows($user, 'miniapp_admin'));
        self::assertFalse($access->allows($user, 'miniapp_admin_not_mapped'));
        self::assertFalse(AdminRoutePermissions::isAdminPath('/app/administrator'));
        self::assertTrue(AdminRoutePermissions::isAdminPath('/app/api/admin/anything'));
    }

    public function testOnlyActiveAdminsGetTheAdminEntryAndHome(): void
    {
        foreach ([self::SUPER => 200, self::ADMIN => 200, self::SUPPORT => 200, self::CUSTOMER_ONLY => 403, self::INACTIVE => 403] as $telegramId => $status) {
            $session = $this->authenticate((string) $telegramId);
            $nav = $this->appGet('/app/view/nav', $session);
            self::assertSame(200 === $status, str_contains($nav, 'data-nav-key="admin"'), $telegramId.': admin entry in the navigation');
            $this->appGet('/app/admin', $session);
            self::assertSame($status, $this->httpStatus(), (string) $telegramId);
        }

        $this->appGet('/app/admin', null);
        self::assertSame(401, $this->httpStatus());
    }

    public function testDeactivationAndRoleChangesApplyToTheNextRequest(): void
    {
        $session = $this->authenticate(self::ADMIN);
        $this->appGet('/app/admin', $session);
        self::assertSame(200, $this->httpStatus());

        $this->em->getConnection()->executeStatement('UPDATE admin_user SET roles = ? WHERE id = ?', [json_encode([]), $this->adminUser->getId()]);
        $this->appGet('/app/admin', $session);
        self::assertSame(403, $this->httpStatus(), 'no role left: no admin area, same session');

        $this->em->getConnection()->executeStatement('UPDATE admin_user SET roles = ? WHERE id = ?', [json_encode([AdminUser::ROLE_ADMIN]), $this->adminUser->getId()]);
        $this->appGet('/app/admin', $session);
        self::assertSame(200, $this->httpStatus());

        $this->em->getConnection()->executeStatement('UPDATE admin_user SET is_active = 0 WHERE id = ?', [$this->adminUser->getId()]);
        $this->appGet('/app/admin', $session);
        self::assertSame(403, $this->httpStatus(), 'deactivated: refused at once');
        $this->appGet('/app/view/home', $session);
        self::assertSame(200, $this->httpStatus(), 'still a customer');
    }

    public function testAdminSessionsAreShorterAndAgedOutOfTheAdminArea(): void
    {
        $ttl = self::getContainer()->get(AdminRouteAccess::class)->sessionTtl();
        self::assertSame(7200, $ttl, 'default: 2 hours');

        $this->postAuth($this->initData(self::ADMIN));
        $adminExpiry = strtotime((string) $this->json()['expiresAt']);
        self::assertEqualsWithDelta(time() + $ttl, $adminExpiry, 60, 'an admin is issued a 2-hour session');
        $this->postAuth($this->initData(self::CUSTOMER_ONLY));
        self::assertGreaterThan(time() + $ttl + 3600, strtotime((string) $this->json()['expiresAt']), 'customers keep the long session');

        $session = $this->authenticate(self::SUPPORT);
        $this->ageSessions(self::SUPPORT, $ttl + 60);
        $this->appGet('/app/admin', $session);
        self::assertSame(401, $this->httpStatus());
        self::assertSame('admin_session_expired', $this->json()['error']);
    }

    public function testConfirmTokensAreBoundToSessionActionTargetAndTime(): void
    {
        $this->authenticate(self::ADMIN);
        $user = $this->miniAppUserFor(self::ADMIN);
        $tokens = new AdminConfirmTokens(new NativeClock(), 'test-secret');
        $token = $tokens->issue($user, 'miniapp_admin_payment_approve', 7);

        self::assertTrue($tokens->isValid($user, 'miniapp_admin_payment_approve', 7, $token));
        self::assertFalse($tokens->isValid($user, 'miniapp_admin_payment_approve', 8, $token), 'another target');
        self::assertFalse($tokens->isValid($user, 'miniapp_admin_payment_reject', 7, $token), 'another action');
        self::assertFalse($tokens->isValid($user, 'miniapp_admin_payment_approve', 7, null));
        self::assertFalse($tokens->isValid($user, 'miniapp_admin_payment_approve', 7, 'garbage'));
        $expired = (time() - 1).substr($token, 10);
        self::assertFalse($tokens->isValid($user, 'miniapp_admin_payment_approve', 7, $expired), 'expired / tampered');

        $this->authenticate(self::ADMIN);
        self::assertFalse($tokens->isValid($this->miniAppUserFor(self::ADMIN), 'miniapp_admin_payment_approve', 7, $token), 'another session');
    }

    public function testClaimOnceRunsAnActionOnceAndFreesTheKeyWhenItFails(): void
    {
        $idempotency = self::getContainer()->get(CheckoutIdempotency::class);
        $runs = 0;
        $action = static function () use (&$runs): array {
            ++$runs;

            return ['message' => 'done '.$runs];
        };

        self::assertSame(['message' => 'done 1'], $idempotency->claimOnce($this->buyer, 'key-aaaaaaaaaaaaaaaa', 'admin:x:1', $action));
        self::assertSame(['message' => 'done 1'], $idempotency->claimOnce($this->buyer, 'key-aaaaaaaaaaaaaaaa', 'admin:x:1', $action), 'a repeat gets the first answer');
        self::assertSame(1, $runs);

        try {
            $idempotency->claimOnce($this->buyer, 'key-aaaaaaaaaaaaaaaa', 'admin:y:1', $action);
            self::fail('the key was used for another action');
        } catch (CheckoutFailure $e) {
            self::assertSame('idempotency_conflict', $e->error);
        }

        $this->em->getConnection()->insert('checkout_request', ['user_id' => $this->buyer->getId(), 'idempotency_key' => 'key-running-bbbbbbbbbb', 'scope' => 'admin:x:1', 'created_at' => date('Y-m-d H:i:s')]);
        try {
            $idempotency->claimOnce($this->buyer, 'key-running-bbbbbbbbbb', 'admin:x:1', $action);
            self::fail('a duplicate of a running call must be refused');
        } catch (CheckoutFailure $e) {
            self::assertSame('idempotency_in_progress', $e->error);
        }

        try {
            $idempotency->claimOnce($this->buyer, 'key-failing-cccccccccc', 'admin:x:1', static fn (): array => throw new \RuntimeException('boom'));
        } catch (\RuntimeException) {
        }
        self::assertSame(['message' => 'done 2'], $idempotency->claimOnce($this->buyer, 'key-failing-cccccccccc', 'admin:x:1', $action), 'a failed call frees its key');
    }

    public function testMiniAppAdminActionsAreAuditedWithTheirSourceAndMasked(): void
    {
        $this->authenticate(self::ADMIN);
        $user = $this->miniAppUserFor(self::ADMIN);
        self::assertSame(AdminActor::SOURCE_MINIAPP, $user->admin?->source);

        self::getContainer()->get(AdminAuditLogger::class)->record('service.extend', 'VpnService', 5, ['days' => 30, 'api_key' => 'sk-live-123456'], $user->admin);
        $row = $this->auditRows()[0];
        self::assertSame(AdminAuditLog::SOURCE_MINIAPP, $row['source']);
        self::assertSame($this->adminUser->getId(), (int) $row['actor_admin_user_id']);
        self::assertSame('5', $row['target_id']);
        self::assertStringNotContainsString('sk-live-123456', (string) $row['details']);
    }

    private function miniAppUserFor(string $telegramId): MiniAppUser
    {
        $account = $this->em->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => $telegramId]);
        self::assertInstanceOf(TelegramAccount::class, $account);
        $actor = self::getContainer()->get(AdminAccessPolicy::class)->botActor($telegramId)?->withSource(AdminActor::SOURCE_MINIAPP);

        return new MiniAppUser($account, $this->sessionRow($telegramId), $actor);
    }
}
