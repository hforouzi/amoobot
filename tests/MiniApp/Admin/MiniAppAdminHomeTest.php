<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\VpnPanel;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The admin home: the web dashboard's sections (DashboardOverview) per role, each card opening the filtered list.
 */
final class MiniAppAdminHomeTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->em->persist((new VpnPanel())->setType('test')->setTitle('Main panel'));
        $this->em->flush();
        $this->startMiniAppClient();
    }

    public function testEachRoleSeesOnlyItsCardsAndTheyLinkToTheFilteredLists(): void
    {
        $super = new Crawler($this->appGet('/app/admin', $this->authenticate(self::SUPER)));
        self::assertSame(200, $this->httpStatus());
        self::assertSame(['attention', 'today', 'services', 'panels'], $super->filter('[data-card]')->each(static fn (Crawler $c): string => (string) $c->attr('data-card')));
        self::assertSame('/app/admin/payments?tab=needs_review', $super->filter('[data-tile="needs_review"]')->attr('href'));
        self::assertSame('/app/admin/services?tab=expiring', $super->filter('[data-tile="expiring"]')->attr('href'));
        self::assertSame('1', trim($super->filter('[data-tile="pending"] b')->text()), 'the submitted receipt waits in "pending"');
        self::assertStringContainsString('Main panel', $super->filter('[data-card="panels"]')->text());

        $admin = new Crawler($this->appGet('/app/admin', $this->authenticate(self::ADMIN)));
        self::assertSame(['attention', 'today', 'services'], $admin->filter('[data-card]')->each(static fn (Crawler $c): string => (string) $c->attr('data-card')), 'no panel health without PANELS_MANAGE');

        $support = new Crawler($this->appGet('/app/admin', $this->authenticate(self::SUPPORT)));
        self::assertSame(['attention', 'today', 'services'], $support->filter('[data-card]')->each(static fn (Crawler $c): string => (string) $c->attr('data-card')));
        self::assertSame(['admin.orders', 'admin.payments', 'admin.customers', 'admin.services'], $support->filter('[data-area]')->each(static fn (Crawler $c): string => (string) $c->attr('data-area')));
        self::assertCount(0, $support->filter('[data-coming-soon]'), 'the four core areas are built');
    }

    public function testTheHomeIsTranslatedWithoutRawKeys(): void
    {
        $session = $this->authenticate(self::SUPER);
        foreach (['', '?lang=en'] as $lang) {
            $html = $this->appGet('/app/admin'.$lang, $session);
            self::assertSame(200, $this->httpStatus());
            self::assertDoesNotMatchRegularExpression(self::RAW_KEY, strip_tags($html), 'raw key in '.($lang ?: 'fa'));
        }
    }

    public function testEveryAdminStringExistsInPersianAndEnglish(): void
    {
        $translator = self::getContainer()->get('translator');
        $keys = static fn (string $locale): array => array_values(array_filter(array_keys($translator->getCatalogue($locale)->all('miniapp')), static fn (string $key): bool => str_starts_with($key, 'admin.')));
        $fa = $keys('fa');
        $en = $keys('en');
        sort($fa);
        sort($en);
        self::assertSame($fa, $en);
        self::assertGreaterThan(150, count($fa));
    }
}
