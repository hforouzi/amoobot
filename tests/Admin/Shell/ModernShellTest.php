<?php

declare(strict_types=1);

namespace App\Tests\Admin\Shell;

use App\Admin\Application\CardReceiptQueue;
use App\Admin\UI\Shell\AdminSections;
use App\Admin\UI\Shell\AdminUiMode;
use App\Branding\BrandingSettings;
use App\Entity\AdminUser;
use App\Payment\Domain\PaymentGatewayType;
use App\Payment\Domain\PaymentStatus;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AD1 (docs/design/admin-redesign.md, 7.1): the modern shell. The 7-item sidebar per role, every menu target and section
 * tab answering 200 / 403 exactly as in classic, the super-admin-only preview (decision 2), the card-to-card receipts
 * badge (decision 5), the brand name (decision 9) and the brand accent, accepted only as a hex colour (decision 15).
 */
final class ModernShellTest extends IntegrationTestCase
{
    private const ROLES = [AdminUser::ROLE_SUPER_ADMIN, AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT];

    /** Sections each role sees (from the permission matrix, RoleAccessWebTest::ROLE_PERMISSIONS). */
    private const SECTIONS_BY_ROLE = [
        AdminUser::ROLE_SUPER_ADMIN => ['dashboard', 'sales', 'customers', 'services', 'catalog', 'messaging', 'settings'],
        AdminUser::ROLE_ADMIN => ['dashboard', 'sales', 'customers', 'services', 'catalog', 'messaging'],
        AdminUser::ROLE_SUPPORT => ['dashboard', 'sales', 'customers', 'services'],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM admin_user');
        $connection->executeStatement('DELETE FROM setting WHERE key_name IN (?, ?, ?)', [AdminUiMode::SETTING, BrandingSettings::NAME, BrandingSettings::ACCENT]);
        self::getContainer()->get('cache.app')->delete(CardReceiptQueue::CACHE_KEY);
        $this->resetShell();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    public function testTheSwitchIsOffByDefault(): void
    {
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $this->client->request('GET', '/admin');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('data-admin-ui="modern"', $this->html());
        self::assertStringNotContainsString('admin-modern.css', $this->html());
        self::assertStringContainsString('Amoobot Admin', $this->html());
    }

    public function testExactlyTheSevenSectionsPerRole(): void
    {
        $this->setLayout('modern');
        foreach (self::ROLES as $role) {
            $this->client->loginUser($this->admin($role), 'main');
            $crawler = $this->client->request('GET', '/admin');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertStringContainsString('data-admin-ui="modern"', $this->html(), $role);

            $labels = $crawler->filter('#main-menu .ea-sidebar-item-label')->each(static fn (Crawler $n): string => trim($n->text()));
            $expected = array_map(fn (string $section): string => $this->trans(AdminSections::SECTIONS[$section][0]), self::SECTIONS_BY_ROLE[$role]);
            self::assertSame($expected, $labels, $role);
        }

        // The owner's labels, in order (fa).
        self::assertSame(
            ['داشبورد', 'فروش و پرداخت‌ها', 'مشتریان', 'سرویس‌ها', 'پلن‌ها و تخفیف', 'پیام‌ها و متن ربات', 'تنظیمات'],
            array_map(fn (array $s): string => $this->trans($s[0]), array_values(AdminSections::SECTIONS)),
        );
    }

    /**
     * Every menu target and every section tab of the modern layout, as each role: the same 200 / 403 as the same URL in
     * classic; and every link the modern menu shows opens.
     */
    public function testEveryModernTargetAnswersAsInClassic(): void
    {
        $sections = self::getContainer()->get(AdminSections::class);
        $urls = [];
        foreach (array_keys(AdminSections::SECTIONS) as $section) {
            foreach (AdminSections::pages($section) as $page) {
                $urls[] = $sections->url($page);
            }
        }
        $urls[] = '/admin';
        self::assertGreaterThan(20, count($urls));

        foreach (self::ROLES as $role) {
            $admin = $this->admin($role);
            $this->client->loginUser($admin, 'main');

            $this->setLayout('modern');
            $menu = $this->client->request('GET', '/admin')->filter('#main-menu a.ea-sidebar-item-link')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
            self::assertCount(count(self::SECTIONS_BY_ROLE[$role]), $menu, $role);
            $urls = array_values(array_unique([...$urls, ...$menu]));

            $modern = [];
            foreach ($urls as $url) {
                $modern[$url] = $this->statusOf($url);
                if (200 === $modern[$url]) {
                    self::assertStringContainsString('data-admin-ui="modern"', $this->html(), $role.' '.$url);
                }
            }
            foreach ($menu as $url) {
                self::assertSame(200, $modern[$url], $role.' menu '.$url);
            }

            $this->setLayout('classic');
            foreach ($urls as $url) {
                $status = $this->statusOf($url);
                self::assertContains($status, [200, 403], $role.' '.$url);
                self::assertSame($status, $modern[$url], sprintf('%s GET %s: classic %d, modern %d', $role, $url, $status, $modern[$url]));
                if (200 === $status) {
                    self::assertStringNotContainsString('data-admin-ui="modern"', $this->html(), $role.' '.$url);
                }
            }
        }
    }

    /** Pages on plain #[Route]s render on the EasyAdmin layout in modern mode, with the same permission as before. */
    public function testPlainRoutePagesRenderOnTheEasyAdminLayout(): void
    {
        $this->setLayout('modern');
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        foreach (['/admin/payment-gateways/install', '/admin/plugins/upload', '/admin/panel-setup/new'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $url);
            self::assertStringNotContainsString('admin-custom-shell', $this->html(), $url);
            self::assertCount(1, $crawler->filter('#main-menu'), $url);
            self::assertCount(1, $crawler->filter('.ea-sidebar-item.is-active'), $url);
            self::assertStringContainsString($this->trans('admin_ui.nav.settings'), $crawler->filter('.ea-sidebar-item.is-active')->text(), $url);
        }
        self::assertStringContainsString($this->trans('payment_gateway.install_submit'), $this->pageOf('/admin/payment-gateways/install'));

        $this->client->loginUser($this->admin(AdminUser::ROLE_ADMIN), 'main');
        $this->client->request('GET', '/admin/payment-gateways/install');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testPreviewIsForSuperAdminsOnly(): void
    {
        // Super admin: ?admin_ui=preview turns it on for the session, ?admin_ui=classic off.
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $this->client->request('GET', '/admin?admin_ui=preview');
        self::assertStringContainsString('data-admin-ui="modern"', $this->html());
        $this->client->request('GET', '/admin/payment');
        self::assertStringContainsString('data-admin-ui="modern"', $this->html(), 'the preview lasts for the session');
        $this->client->request('GET', '/admin?admin_ui=classic');
        self::assertStringNotContainsString('data-admin-ui="modern"', $this->html());
        $this->client->request('GET', '/admin/payment');
        self::assertStringNotContainsString('data-admin-ui="modern"', $this->html());

        // Admin and support: the parameter is ignored, nothing is stored.
        foreach ([AdminUser::ROLE_ADMIN, AdminUser::ROLE_SUPPORT] as $role) {
            $this->client->loginUser($this->admin($role), 'main');
            $this->client->request('GET', '/admin?admin_ui=preview');
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertStringNotContainsString('data-admin-ui="modern"', $this->html(), $role);
            self::assertNull($this->client->getRequest()->getSession()->get(AdminUiMode::SESSION_PREVIEW), $role);
            $this->client->request('GET', '/admin/payment');
            self::assertStringNotContainsString('data-admin-ui="modern"', $this->html(), $role);
        }
    }

    public function testAPreviewFlagCountsOnlyForASuperAdmin(): void
    {
        self::assertTrue(AdminUiMode::maySeePreview((new AdminUser())->setRoles([AdminUser::ROLE_SUPER_ADMIN])));
        self::assertFalse(AdminUiMode::maySeePreview((new AdminUser())->setRoles([AdminUser::ROLE_ADMIN])));
        self::assertFalse(AdminUiMode::maySeePreview((new AdminUser())->setRoles([AdminUser::ROLE_SUPPORT])));
        self::assertFalse(AdminUiMode::maySeePreview(null));
    }

    public function testTheEnvOverrideForcesClassic(): void
    {
        $this->setLayout('modern');
        $container = self::getContainer();
        $forced = new AdminUiMode($this->em, $container->get('request_stack'), $container->get('security.helper'), '1');
        self::assertTrue($forced->isForcedClassic());
        self::assertFalse($forced->isModern());

        $notForced = new AdminUiMode($this->em, $container->get('request_stack'), $container->get('security.helper'), '0');
        self::assertTrue($notForced->isModern());
        self::assertSame(AdminUiMode::MODERN, $notForced->setting());
    }

    public function testAnyOtherSettingValueIsClassic(): void
    {
        foreach (['', 'Modern ', 'MODERN', 'yes', 'classic'] as $value) {
            $this->setLayout($value);
            $mode = self::getContainer()->get(AdminUiMode::class);
            self::assertSame('modern' === strtolower(trim($value)) ? AdminUiMode::MODERN : AdminUiMode::CLASSIC, $mode->setting(), var_export($value, true));
        }
    }

    /** The red badge counts card-to-card receipts waiting, and nothing else (decision 5). */
    public function testTheBadgeCountsCardToCardReceiptsOnly(): void
    {
        $user = $this->createUser();
        $plan = $this->createPlan();
        $card = $this->createGateway(PaymentGatewayType::MANUAL_CARD);
        $zibal = $this->createGateway(PaymentGatewayType::ZIBAL);

        $payment = fn (string $status, ?\App\Entity\PaymentGateway $gateway = null) => $this->createPayment($this->createOrder($user, $plan), $gateway ?? $card, $status);

        // Counted: a pending card payment with a photo, a submitted one with a tracking code.
        $payment(PaymentStatus::PENDING)->setReceiptFileId('photo-1');
        $payment(PaymentStatus::SUBMITTED)->setTrackingCode('123456');
        // Not counted: no receipt yet, an empty receipt, a gateway payment, needs review, confirmed, rejected.
        $payment(PaymentStatus::PENDING);
        $payment(PaymentStatus::PENDING)->setTrackingCode('');
        $payment(PaymentStatus::PENDING, $zibal)->setTrackingCode('zibal-track');
        $payment(PaymentStatus::NEEDS_REVIEW)->setReceiptFileId('photo-2');
        $payment(PaymentStatus::CONFIRMED)->setReceiptFileId('photo-3');
        $payment(PaymentStatus::REJECTED)->setTrackingCode('999');
        $this->em->flush();

        self::assertSame(2, self::getContainer()->get(CardReceiptQueue::class)->count());

        $this->setLayout('modern');
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPPORT), 'main');
        $crawler = $this->client->request('GET', '/admin');
        $badge = $crawler->filter('[data-admin-badge="card-receipts"]');
        self::assertCount(1, $badge);
        self::assertSame('2', trim($badge->text()));
        self::assertStringContainsString($this->trans('admin_ui.nav.sales'), $badge->ancestors()->filter('.ea-sidebar-item')->first()->text());
    }

    public function testNoBadgeWithoutReceipts(): void
    {
        $this->setLayout('modern');
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        self::assertCount(0, $this->client->request('GET', '/admin')->filter('[data-admin-badge]'));
    }

    public function testBrandNameInHeaderAndTab(): void
    {
        $this->setSetting(BrandingSettings::NAME, 'Shop & Co');
        $this->setLayout('modern');
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $crawler = $this->client->request('GET', '/admin/payment');

        self::assertStringEndsWith(' · Shop & Co', $crawler->filter('title')->text());
        self::assertSame('Shop & Co', trim($crawler->filter('#header-logo .logo-custom')->text()));
        self::assertStringContainsString('Shop &amp; Co', $this->html());
        self::assertStringNotContainsString('Amoobot Admin', $this->html());

        // No brand set: "Amoobot".
        $this->em->getConnection()->executeStatement('DELETE FROM setting WHERE key_name = ?', [BrandingSettings::NAME]);
        $this->resetShell();
        $crawler = $this->client->request('GET', '/admin/payment');
        self::assertSame('Amoobot', trim($crawler->filter('#header-logo .logo-custom')->text()));
    }

    /** The accent is the brand colour, and nothing but a #rrggbb colour reaches the page (decision 15). */
    public function testTheAccentIsAcceptedOnlyAsAHexColour(): void
    {
        $this->setLayout('modern');
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');

        $this->setSetting(BrandingSettings::ACCENT, '#1D4ED8');
        $this->client->request('GET', '/admin');
        self::assertStringContainsString('style="--admin-accent: #1d4ed8; --admin-accent-fg: #ffffff;"', $this->html());

        foreach (['red', '#12345', '#1234567', '#12345g', 'url(x)', '#123456;}body{display:none', '#123456"><script>', ' '] as $bad) {
            $this->setSetting(BrandingSettings::ACCENT, $bad);
            $this->client->request('GET', '/admin');
            self::assertStringContainsString('style="--admin-accent: #166534;', $this->html(), $bad);
            self::assertStringNotContainsString('display:none', $this->html(), $bad);
        }

        self::assertSame('#166534', \App\Admin\UI\Shell\AdminBrand::validAccent('#123456;x'));
        self::assertSame('#abcdef', \App\Admin\UI\Shell\AdminBrand::validAccent('#ABCDEF'));
    }

    /** No font or script from outside: the font is self-hosted, Stimulus vendored. */
    public function testNoExternalAssets(): void
    {
        $this->setLayout('modern');
        $this->client->loginUser($this->admin(AdminUser::ROLE_SUPER_ADMIN), 'main');
        $this->client->request('GET', '/admin');
        self::assertStringContainsString('/assets/admin/admin-modern.css', $this->html());
        self::assertStringContainsString('<script type="module" src="/assets/admin/modern.js"></script>', $this->html());
        // Links of this site are absolute (http://localhost in tests); nothing points elsewhere.
        self::assertDoesNotMatchRegularExpression('#(src|href)="(https?:)?//(?!localhost[/:"])#', $this->html());

        $public = dirname(__DIR__, 3).'/public/assets/admin/';
        foreach (['admin.css', 'admin-modern.css', 'modern.js', 'controllers/theme_controller.js', 'controllers/collapse_controller.js'] as $file) {
            self::assertDoesNotMatchRegularExpression('#https?://#', (string) file_get_contents($public.$file), $file);
        }
        self::assertFileExists($public.'fonts/Vazirmatn-wght.woff2');
        self::assertStringContainsString('export { Application', (string) file_get_contents($public.'vendor/stimulus.js'));
    }

    /** Every admin controller has a section, so every page lights a sidebar item. */
    public function testEveryAdminControllerHasASection(): void
    {
        $outside = ['AdminLocaleController', 'AdminSecurityController', 'LegacyAdminRedirectController', 'ConfirmPages', 'TranslatedFlashes'];
        $files = [...(glob(dirname(__DIR__, 3).'/src/Admin/UI/*.php') ?: []), ...(glob(dirname(__DIR__, 3).'/src/Admin/UI/Crud/*.php') ?: [])];
        foreach ($files as $file) {
            $short = basename($file, '.php');
            if (in_array($short, $outside, true)) {
                continue;
            }
            $class = 'App\\Admin\\UI\\'.(str_contains($file, '/Crud/') ? 'Crud\\' : '').$short;
            self::assertNotNull(AdminSections::of(null, $class), $class.' has no section (AdminSections::CONTROLLERS)');
        }
        foreach (array_keys(AdminSections::SECTIONS) as $section) {
            foreach (AdminSections::pages($section) as $page) {
                self::assertSame($section, AdminSections::of($page['route'], $page['controller']), $page['key']);
            }
        }
    }

    private function statusOf(string $url): int
    {
        $this->client->request('GET', $url);
        // A list folded into another page redirects there.
        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }

        return $this->client->getResponse()->getStatusCode();
    }

    private function pageOf(string $url): string
    {
        $this->client->request('GET', $url);

        return $this->html();
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function setLayout(string $value): void
    {
        $this->setSetting(AdminUiMode::SETTING, $value);
    }

    private function setSetting(string $key, string $value): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM setting WHERE key_name = ?', [$key]);
        $connection->executeStatement("INSERT INTO setting (key_name, value, type, created_at) VALUES (?, ?, 'string', NOW())", [$key, $value]);
        $this->resetShell();
    }

    /** The shell's readers keep what they read for the request; the test client reuses them. */
    private function resetShell(): void
    {
        self::getContainer()->get(AdminUiMode::class)->reset();
        self::getContainer()->get(BrandingSettings::class)->reset();
    }

    private function admin(string $role): AdminUser
    {
        $admin = (new AdminUser())->setEmail(strtolower($role).'-'.bin2hex(random_bytes(3)).'@shop.test')->setPassword('unused')->setRoles([$role])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }

    private function trans(string $key): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, [], 'admin', 'fa');
    }
}
