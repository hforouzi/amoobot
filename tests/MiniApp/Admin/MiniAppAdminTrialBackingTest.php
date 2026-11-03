<?php

declare(strict_types=1);

namespace App\Tests\MiniApp\Admin;

use App\Entity\TrialPlan;
use App\Entity\VpnInbound;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\MiniAppAdminFixtures;
use App\Tests\Support\MiniAppTestTrait;
use App\Tests\Support\TrialBackingFixtures;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Mini App admin never shows a trial's backing plan on the sales side (docs/design/trial-backing-plans.md): the
 * plan list (tabs, counts, search), the discount form's plan picker, the bulk price page and the panel plan counts;
 * and deleting a trial plan there does what the web does (TrialPlanAdminService::delete()).
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

    public function testABackingPlansUrlSaysWhereItBelongsInsteadOfTheForm(): void
    {
        $page = new Crawler($this->appGet('/app/admin/plans/'.$this->backing->getId().'?lang=en', $this->session));

        self::assertSame(200, $this->httpStatus());
        self::assertCount(0, $page->filter('form#plan-form'), 'no form');
        self::assertStringContainsString('"[Trial] Gift" is the hidden plan of the trial plan "Gift"', $page->filter('[data-trial-backing]')->text());
        self::assertSame('/app/admin/trial-plans/'.$this->trial->getId(), $page->filter('[data-trial-link]')->attr('href'));

        $orphan = new Crawler($this->appGet('/app/admin/plans/'.$this->orphan->getId().'?lang=en', $this->session));
        self::assertStringContainsString('is the hidden plan of a trial plan deleted earlier', $orphan->filter('[data-trial-backing]')->text());
        self::assertSame('/app/admin/trial-plans', $orphan->filter('[data-trial-link]')->attr('href'));
    }

    public function testEveryCallOnABackingPlanIsRefusedWithTheMessageAndChangesNothing(): void
    {
        $before = $this->row('plan', (int) $this->backing->getId());
        $id = $this->backing->getId();
        $calls = [
            "/app/api/admin/plans/$id/activate" => [],
            "/app/api/admin/plans/$id/deactivate" => [],
            "/app/api/admin/plans/$id/delete" => [],
            "/app/api/admin/plans/$id/duplicate" => [],
            "/app/api/admin/plans/$id" => ['title' => 'Free!', 'durationDays' => '30', 'price' => '1', 'isActive' => '1'],
            '/app/api/admin/plans/check' => ['id' => (string) $id, 'title' => 'x', 'durationDays' => '1', 'price' => '1'],
        ];
        foreach ($calls as $path => $body) {
            $answer = $this->appPostJson($path.'?lang=en', $this->session, $body, self::newKey());
            self::assertSame(409, $this->httpStatus(), $path.': '.json_encode($answer));
            self::assertSame('trial_backing_plan', $answer['error'] ?? null, $path);
            self::assertStringContainsString('is the hidden plan of the trial plan "Gift"', (string) ($answer['message'] ?? ''), $path);
        }
        self::assertSame($before, $this->row('plan', (int) $id));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan WHERE title LIKE ?', ['%Gift%']), 'no copy made');
        self::assertSame([], array_column($this->auditRows(), 'action'), 'nothing audited: nothing happened');
    }

    public function testANormalPlanCalledTrialIsManagedAsBefore(): void
    {
        $page = new Crawler($this->appGet('/app/admin/plans/'.$this->lookalike->getId().'?lang=en', $this->session));
        self::assertCount(1, $page->filter('form#plan-form'));

        $this->confirmedPost('/app/api/admin/plans/'.$this->lookalike->getId().'/deactivate', $this->session);

        self::assertSame(200, $this->httpStatus());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT is_active FROM plan WHERE id = ?', [$this->lookalike->getId()]));
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

    public function testDeletingATrialPlanFromTheMiniAppDoesWhatTheWebDoes(): void
    {
        $backingId = (int) $this->backing->getId();
        $page = new Crawler($this->appGet('/app/admin/trial-plans/'.$this->trial->getId().'?lang=en', $this->session));
        self::assertCount(1, $page->filter('[data-group="delete"] [data-admin-action="delete"]'));

        // Claimed: the backing plan stays, inactive and hidden, and the admin is told why.
        $answer = $this->confirmedPost('/app/api/admin/trial-plans/'.$this->trial->getId().'/delete', $this->session);
        self::assertSame(200, $this->httpStatus(), json_encode($answer, JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString('[Trial] Gift', (string) ($answer['message'] ?? ''));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM trial_plan'));
        self::assertSame(['is_active' => 0, 'price' => 0], array_map('intval', $this->em->getConnection()->fetchAssociative('SELECT is_active, price FROM plan WHERE id = ?', [$backingId]) ?: []));
        $audit = $this->auditRows();
        self::assertSame(['trial_plan.delete'], array_column($audit, 'action'));
        self::assertStringContainsString('backing_kept', (string) $audit[0]['details']);
        $list = new Crawler($this->appGet('/app/admin/plans?tab=inactive&lang=en', $this->session));
        self::assertCount(0, $list->filter('[data-plan-id]'), 'still hidden from the plans');

        // Nothing refers to it: it goes too.
        $inbound = $this->em->find(VpnInbound::class, $this->trialInbound->getId()) ?? self::fail('inbound');
        $plan = $this->createPlan(0, $inbound)->setTitle('[Trial] Unused')->setIsActive(false);
        $unused = (new TrialPlan())->setTitle('Unused')->setIsActive(true)->setDurationDays(1)->setTrafficGb(1)->setInbound($inbound)->setBackingPlan($plan);
        $this->em->persist($unused);
        $this->em->flush();
        $planId = (int) $plan->getId();
        $this->confirmedPost('/app/api/admin/trial-plans/'.$unused->getId().'/delete', $this->session);
        self::assertSame(200, $this->httpStatus());
        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM plan WHERE id = ?', [$planId]));

        // Support may not.
        $support = $this->authenticate(self::SUPPORT);
        $this->appPostJson('/app/api/admin/trial-plans/'.$this->trial->getId().'/delete', $support, [], self::newKey());
        self::assertSame(403, $this->httpStatus());
    }
}
