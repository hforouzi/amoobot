<?php

declare(strict_types=1);

namespace App\Tests\MiniApp;

use App\Entity\AdminUser;
use App\Entity\MiniAppSession;
use App\Entity\TelegramAccount;
use App\MiniApp\Navigation\MiniAppAudience;
use App\MiniApp\Navigation\MiniAppSection;
use App\MiniApp\Navigation\MiniAppSectionProvider;
use App\MiniApp\Navigation\MiniAppSections;
use App\MiniApp\Security\MiniAppUser;
use App\Security\AdminAccessPolicy;
use App\Security\AdminPermission;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\MissingTranslationRecorder;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * The app shell's role-aware navigation: built from the section registry (MiniAppSections) and AdminAccessPolicy,
 * and every section route enforces the same rule on the server.
 */
final class MiniAppNavigationTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM mini_app_session');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->startMiniAppClient();
    }

    public function testACustomerSeesHomeServicesAppsAndHelpButNoAdmin(): void
    {
        $session = $this->authenticate('8301');

        self::assertSame(['home', 'shop', 'services', 'apps', 'help'], $this->navKeys($session));

        $this->appGet('/app/admin', $session);
        self::assertSame(403, $this->httpStatus(), 'the server refuses it too, not only the menu');
    }

    public function testAdminsGetTheAdminEntryWithTheAreasTheirRoleAllows(): void
    {
        $this->linkedAdmin('support@example.test', AdminUser::ROLE_SUPPORT, '8302');
        $session = $this->authenticate('8302');

        self::assertSame(['home', 'shop', 'services', 'apps', 'help', 'admin'], $this->navKeys($session));

        $html = $this->appGet('/app/admin', $session);
        self::assertSame(200, $this->httpStatus());
        $areas = (new Crawler($html))->filter('[data-area]')->each(static fn (Crawler $c): string => (string) $c->attr('data-area'));
        self::assertSame(['admin.orders', 'admin.payments', 'admin.customers', 'admin.services'], $areas, 'support: read-only areas only');
        self::assertStringNotContainsString('data-coming-soon', $html, 'their four areas are built');

        $this->appGet('/app/admin', $this->authenticate(self::ENV_ADMIN));
        $areas = (new Crawler((string) $this->client->getResponse()->getContent()))->filter('[data-area]')->count();
        self::assertSame(11, $areas, 'super admin (TELEGRAM_ADMIN_CHAT_ID): every area');
    }

    public function testADeactivatedAdminLosesTheAdminSectionAtOnce(): void
    {
        $admin = $this->linkedAdmin('ops@example.test', AdminUser::ROLE_ADMIN, '8303');
        $session = $this->authenticate('8303');
        $this->appGet('/app/admin', $session);
        self::assertSame(200, $this->httpStatus());

        $this->em->getConnection()->executeStatement('UPDATE admin_user SET is_active = 0 WHERE id = ?', [$admin->getId()]);

        self::assertNotContains('admin', $this->navKeys($session));
        $this->appGet('/app/admin', $session);
        self::assertSame(403, $this->httpStatus());
    }

    public function testEveryAppRouteIsPublicOnPurposeOrGuardedByASection(): void
    {
        $sections = self::getContainer()->get(MiniAppSections::class);
        $unguarded = [];
        foreach (self::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/app')) {
                continue;
            }
            $section = $route->getDefault('_miniapp_section');
            if (null === $section) {
                $unguarded[] = $name;
                continue;
            }
            self::assertNotNull($sections->get((string) $section), $name.' names an unknown section');
        }
        sort($unguarded);

        // Sign-in, the empty shell, the session itself and the menu (built per user): no section to check.
        self::assertSame(['miniapp_auth', 'miniapp_entry', 'miniapp_logout', 'miniapp_me', 'miniapp_nav'], $unguarded);
    }

    public function testFutureSectionsPlugInThroughAProviderWithoutTouchingTheShell(): void
    {
        $plugin = new class implements MiniAppSectionProvider {
            public function sections(): iterable
            {
                yield new MiniAppSection('reports', 'nav.home', '📈', 25, MiniAppAudience::ADMIN, [AdminPermission::LOGS_VIEW]);
                yield new MiniAppSection('admin.reports', 'nav.home', '📈', 90, MiniAppAudience::ADMIN, [AdminPermission::LOGS_VIEW], parent: 'admin');
            }
        };
        $base = self::getContainer()->get(MiniAppSections::class);
        $sections = new MiniAppSections([...$base->providers(), $plugin], self::getContainer()->get(AdminAccessPolicy::class));

        $this->linkedAdmin('a@example.test', AdminUser::ROLE_ADMIN, '8304');
        $this->linkedAdmin('s@example.test', AdminUser::ROLE_SUPER_ADMIN, '8305');
        $this->createUser('8304');
        $this->createUser('8305');
        $admin = $this->miniAppUser('8304');
        $super = $this->miniAppUser('8305');

        self::assertNotContains('reports', $this->keys($sections->navigation($admin)), 'ROLE_ADMIN has no LOGS_VIEW');
        self::assertSame(['home', 'shop', 'services', 'reports', 'apps', 'help', 'admin'], $this->keys($sections->navigation($super)), 'ordered by position');
        self::assertContains('admin.reports', $this->keys($sections->children('admin', $super)));
        self::assertFalse($sections->isAllowed('reports', $admin));
    }

    public function testTheShellFragmentsArePrivateTranslatedAndRtl(): void
    {
        $recorder = self::getContainer()->get(MissingTranslationRecorder::class);
        $recorder->reset();
        $session = $this->authenticate(self::ENV_ADMIN);

        foreach (['', '?lang=en'] as $query) {
            foreach (['/app/view/nav', '/app/view/home', '/app/view/services', '/app/view/apps', '/app/view/help', '/app/admin'] as $path) {
                $this->appGet($path.$query, $session);
                self::assertSame(200, $this->httpStatus(), $path.$query);
                self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), $path);
            }
            $this->appGet('/app'.$query, null);
        }
        self::assertSame([], $recorder->missing(), 'no raw translation keys');

        $html = $this->appGet('/app/view/home', $session);
        self::assertMatchesRegularExpression('/data-view="home"[^>]*data-title="[^"]+"/', $html);
    }

    /**
     * @param array{token: string, csrf: string} $session
     *
     * @return list<string>
     */
    private function navKeys(array $session): array
    {
        $html = $this->appGet('/app/view/nav', $session);
        self::assertSame(200, $this->httpStatus());

        return (new Crawler($html))->filter('a[data-nav-key]')->each(static fn (Crawler $c): string => (string) $c->attr('data-nav-key'));
    }

    private function miniAppUser(string $telegramId): MiniAppUser
    {
        $account = $this->em->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => $telegramId]);
        self::assertNotNull($account);
        $session = new MiniAppSession(str_repeat('0', 64), $telegramId, str_repeat('0', 64), new \DateTimeImmutable(), new \DateTimeImmutable('+1 hour'));

        return new MiniAppUser($account, $session, self::getContainer()->get(AdminAccessPolicy::class)->botActor($telegramId));
    }

    /**
     * @param list<MiniAppSection> $sections
     *
     * @return list<string>
     */
    private function keys(array $sections): array
    {
        return array_map(static fn (MiniAppSection $s): string => $s->key, $sections);
    }
}
