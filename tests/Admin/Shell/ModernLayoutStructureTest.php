<?php

declare(strict_types=1);

namespace App\Tests\Admin\Shell;

use App\Admin\UI\Shell\AdminUiMode;
use App\Branding\BrandingSettings;
use App\Entity\AdminUser;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The modern layout's page structure (fix of the AD1 preview, where the page was squeezed into a few pixels):
 * EasyAdmin's .content-wrapper is a two-column grid on desktop [page | resize handle], so it must hold exactly one page
 * item and the handle. The section tabs live inside that item, never as a sibling of the page. The stylesheet pins the
 * grid columns to minmax(0, 1fr) and gives the sidebar a fixed width; the sidebar's bottom tools are one per row.
 */
final class ModernLayoutStructureTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM admin_user');
        $connection->executeStatement('DELETE FROM setting WHERE key_name = ?', [AdminUiMode::SETTING]);
        $connection->executeStatement("INSERT INTO setting (key_name, value, type, created_at) VALUES (?, 'modern', 'string', NOW())", [AdminUiMode::SETTING]);
        self::getContainer()->get(AdminUiMode::class)->reset();
        self::getContainer()->get(BrandingSettings::class)->reset();

        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $admin = (new AdminUser())->setEmail('layout-super@shop.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN])->setIsActive(true);
        $this->em->persist($admin);
        $this->em->flush();
        $this->client->loginUser($admin, 'main');
    }

    /** Pages with and without section tabs, EasyAdmin pages and a plain-route page, in both locales. */
    public function testTheContentWrapperHoldsOnePageItemAndTheResizeHandle(): void
    {
        foreach (['fa', 'en'] as $locale) {
            $this->client->request('GET', '/admin/locale/'.$locale.'?redirect_to=/admin');
            foreach (['/admin', '/admin/wallets', '/admin/payment', '/admin/settings', '/admin/payment-gateways/install'] as $url) {
                $crawler = $this->client->request('GET', $url);
                $where = $locale.' '.$url;
                self::assertSame(200, $this->client->getResponse()->getStatusCode(), $where);
                self::assertSame('modern', $crawler->filter('body')->attr('data-admin-ui'), $where);
                self::assertSame('fa' === $locale ? 'rtl' : 'ltr', $crawler->filter('html')->attr('dir'), $where);

                $children = $crawler->filter('.content-wrapper')->children()->each(static fn (Crawler $c): string => $c->nodeName().'.'.(string) $c->attr('class'));
                self::assertSame(['div.admin-content-column', 'div.resizer-handler resizer-handler-right'], $children, $where);

                $column = $crawler->filter('.content-wrapper > .admin-content-column')->children()->each(static fn (Crawler $c): string => (string) $c->attr('class'));
                self::assertSame('content', end($column), $where.': the page is the column\'s last item');
                self::assertContains(count($column), [1, 2], $where);
                if (2 === count($column)) {
                    self::assertSame('admin-section-tabs', $column[0], $where);
                    self::assertGreaterThan(1, $crawler->filter('.admin-section-tabs .nav-link')->count(), $where);
                }
            }
        }

        // A section of several pages shows its tabs; the dashboard has none (no empty element either).
        self::assertCount(1, $this->client->request('GET', '/admin/wallets')->filter('.admin-content-column > .admin-section-tabs'));
        self::assertCount(0, $this->client->request('GET', '/admin')->filter('.admin-section-tabs'));
    }

    public function testTheSidebarBottomToolsAreOnePerRow(): void
    {
        $crawler = $this->client->request('GET', '/admin');
        $tools = $crawler->filter('.ea-sidebar-footer .admin-sidebar-tools');
        self::assertCount(1, $tools);
        self::assertCount(2, $tools->filter('.admin-sidebar-toggles > button.admin-sidebar-tool'));
        foreach ($tools->filter('.admin-sidebar-tool') as $button) {
            $button = new Crawler($button);
            self::assertCount(1, $button->filter('i.fa[aria-hidden="true"]'), 'an icon');
            self::assertCount(1, $button->filter('.admin-sidebar-tool-label'), 'a label');
            self::assertNotSame('', trim((string) $button->attr('aria-label')));
        }
        self::assertSame('collapse', $tools->filter('[data-action="collapse#toggle"]')->attr('data-controller'));
        self::assertSame('admin-sidebar-compact', $tools->filter('[data-action="collapse#toggle"]')->attr('data-collapse-class-value'));
        self::assertCount(1, $tools->filter('.admin-locale-switcher'));
        self::assertCount(1, $tools->filter('.admin-sidebar-user a.admin-sidebar-logout'));
    }

    /** The rules the layout depends on (it can't be looked at in a test, so its stylesheet is pinned instead). */
    public function testTheStylesheetPinsTheLayout(): void
    {
        $css = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 3).'/public/assets/admin/admin-modern.css'));

        self::assertStringContainsString('--sidebar-max-width: 260px;', $css);
        self::assertStringContainsString('body[data-admin-ui="modern"] .wrapper { grid-template-columns: var(--sidebar-max-width) minmax(0, 1fr); }', $css);
        self::assertStringContainsString('body[data-admin-ui="modern"] .content-wrapper { grid-template-columns: minmax(0, 1fr) var(--resize-handler-width); }', $css);
        self::assertStringContainsString('body[data-admin-ui="modern"].admin-sidebar-compact { --sidebar-max-width: 76px; }', $css);
        self::assertStringContainsString('white-space: nowrap;', $css);
        self::assertStringContainsString('min-block-size: var(--admin-target);', $css);
        self::assertStringContainsString('--admin-target: 44px;', $css);

        // RTL first: logical properties only, no direction-specific rules or physical sides (comments aside).
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        self::assertSame(0, preg_match('/\[dir[=\]]/', $code), 'a [dir] rule');
        self::assertSame(0, preg_match('/(margin|padding|border)-(left|right)\b|\b(left|right)\s*:/', $code), 'a physical side');
        // Every rule stays inside the modern scope, so classic is untouched.
        preg_match_all('/([^{}]+)\{[^{}]*\}/', (string) preg_replace('/@media[^{]+\{/', '', $code), $rules);
        foreach ($rules[1] as $selectors) {
            foreach (explode(',', $selectors) as $selector) {
                if ('' !== trim($selector)) {
                    self::assertStringStartsWith('body[data-admin-ui="modern"]', trim($selector), $selector);
                }
            }
        }
    }
}
