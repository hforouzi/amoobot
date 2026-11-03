<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\TrialBackingFixtures;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Mini App admin never shows a trial's backing plan on the sales side (docs/design/trial-backing-plans.md): the
 * plan list (tabs, counts, search), the discount form's plan picker, the bulk price page and the panel plan counts.
 */
final class MiniAppAdminTrialBackingTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use MiniAppAdminFixtures;
    use MiniAppTestTrait;
    use TrialBackingFixtures;

    /** @var array{token: string, csrf: string} */
    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminCast();
        $this->setUpTrialBacking();
        $this->startMiniAppClient();
        $this->session = $this->authenticate(self::ADMIN);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM trial_claim');
        $this->em->getConnection()->executeStatement('DELETE FROM trial_plan');
        parent::tearDown();
    }

    public function testThePlanListItsCountsAndItsSearchHideThem(): void
    {
        $page = new Crawler($this->appGet('/app/admin/plans?lang=en', $this->session));
        self::assertSame(200, $this->httpStatus());
        self::assertListsSalesPlansOnly(implode("\n", $page->filter('[data-plan-id]')->each(static fn (Crawler $row): string => $row->text())), 'Mini App plan list');
        // The cast's plan, Gold and the lookalike; all active.
        self::assertSame(['3', '3', '0'], $page->filter('[data-chip] .chip-count')->each(static fn (Crawler $c): string => trim($c->text())));

        $inactive = new Crawler($this->appGet('/app/admin/plans?tab=inactive&lang=en', $this->session));
        self::assertCount(0, $inactive->filter('[data-plan-id]'), 'backing plans are inactive, and still not listed');

        $search = new Crawler($this->appGet('/app/admin/plans?q='.rawurlencode('[Trial]').'&lang=en', $this->session));
        $rows = $search->filter('[data-plan-id]')->each(static fn (Crawler $row): string => $row->text());
        self::assertCount(1, $rows, 'only the normal plan called "[Trial] ..." is found');
        self::assertStringContainsString('[Trial] Lookalike', $rows[0]);
    }

    public function testTheDiscountFormPlanPickerHidesThem(): void
    {
        $page = new Crawler($this->appGet('/app/admin/discounts/new?lang=en', $this->session));

        self::assertSame(200, $this->httpStatus());
        $options = $page->filter('select[name="plan"] option')->each(static fn (Crawler $o): string => trim($o->text()));
        self::assertListsSalesPlansOnly(implode("\n", $options), 'Mini App discount plan picker');
    }

    public function testTheBulkPricePageHidesThem(): void
    {
        $page = new Crawler($this->appGet('/app/admin/plans/bulk?lang=en', $this->session));

        self::assertSame(200, $this->httpStatus());
        self::assertListsSalesPlansOnly(implode("\n", $page->filter('[data-plan-choices] label')->each(static fn (Crawler $l): string => $l->text())), 'Mini App bulk price page');
    }

    public function testThePanelPlanCountsLeaveThemOut(): void
    {
        $panelId = $this->trialInbound->getPanel()->getId();
        // Panels need PANELS_MANAGE: the super admin (the env admin chat).
        $this->session = $this->authenticate(self::SUPER);

        $list = new Crawler($this->appGet('/app/admin/panels?lang=en', $this->session));
        self::assertSame('2', trim($list->filter('[data-panel-id="'.$panelId.'"] [data-plan-count]')->text()), 'Gold and the lookalike');
        $panel = new Crawler($this->appGet('/app/admin/panels/'.$panelId.'?lang=en', $this->session));
        self::assertSame(['2'], $panel->filter('[data-plan-count]')->each(static fn (Crawler $c): string => trim($c->text())));
    }
}
