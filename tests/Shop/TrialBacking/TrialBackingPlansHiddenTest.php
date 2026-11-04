<?php

declare(strict_types=1);

namespace App\Tests\Shop\TrialBacking;

use App\Entity\DiscountCode;
use App\Entity\Plan;
use App\Entity\Reseller;
use App\Entity\Setting;
use App\MiniApp\Admin\Catalog\CatalogInput;
use App\Reseller\Application\ResellerCheckout;
use App\Reseller\Application\ResellerSettings;
use App\Shop\Application\Catalog\PlanInboundUsage;
use App\Shop\Application\Catalog\TrialBackingPlans;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Shop\Application\PlanPriceAdjustmentService;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\TrialBackingFixtures;
use App\Wallet\Application\WalletSettings;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A trial's backing plan is never listed or offered on the sales side (docs/design/trial-backing-plans.md, fix 1): one
 * test per place found in step 0. Backing plans are known by relation (a trial plan points at it, or a trial order was
 * made on it), never by the "[Trial]" title: the normal plan "[Trial] Lookalike" is listed everywhere as before.
 */
final class TrialBackingPlansHiddenTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use TrialBackingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTrialBacking();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM trial_claim');
        $this->em->getConnection()->executeStatement('DELETE FROM trial_plan');
        parent::tearDown();
    }

    public function testBackingPlansAreKnownByRelationNotByTitle(): void
    {
        $backingPlans = self::getContainer()->get(TrialBackingPlans::class);
        $ids = [(int) $this->gold->getId(), (int) $this->lookalike->getId(), (int) $this->backing->getId(), (int) $this->orphan->getId()];

        self::assertTrue($backingPlans->isBacking($this->backing));
        self::assertTrue($backingPlans->isBacking($this->orphan), 'a trial order is enough (its trial plan was deleted)');
        self::assertFalse($backingPlans->isBacking($this->gold));
        self::assertFalse($backingPlans->isBacking($this->lookalike), 'the title is never looked at');
        self::assertFalse($backingPlans->isBacking(new Plan()));
        $among = $backingPlans->backingAmong($ids);
        sort($among);
        self::assertSame([(int) $this->backing->getId(), (int) $this->orphan->getId()], $among);
        self::assertSame([(int) $this->backing->getId(), (int) $this->orphan->getId()], $backingPlans->allIds());
        self::assertSame(['Gold', '[Trial] Lookalike'], array_map(static fn (Plan $p): string => $p->getTitle(), $backingPlans->salesPlans()));
        self::assertSame(2, $backingPlans->countSalesPlans());
        self::assertSame(['[Trial] Lookalike'], array_map(static fn (Plan $p): string => $p->getTitle(), $backingPlans->salesPlans(['price' => 50000])));
        self::assertSame($this->trial->getId(), $backingPlans->trialPlanOf($this->backing)?->getId());
        self::assertNull($backingPlans->trialPlanOf($this->orphan));
    }

    public function testTheWebPlanListItsSearchAndItsFiltersHideThem(): void
    {
        $client = $this->superAdminBrowser();
        $client->request('GET', '/admin/plan');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertListsSalesPlansOnly((string) $client->getResponse()->getContent(), 'plan list');

        $client->request('GET', '/admin/plan?query='.rawurlencode('[Trial]'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('[Trial] Lookalike', $html, 'the search finds the normal plan');
        self::assertStringNotContainsString('[Trial] Gift', $html);
        self::assertStringNotContainsString('[Trial] Old', $html);

        // The filter on "active" (backing plans are inactive): still not there.
        $client->request('GET', '/admin/plan?filters[isActive]=0');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('[Trial] Gift', (string) $client->getResponse()->getContent());
    }

    public function testTheWebDiscountCodePlanPickerHidesThemButKeepsACodesOwnPlan(): void
    {
        $client = $this->superAdminBrowser();
        $crawler = $client->request('GET', '/admin/discount-code/new');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $options = $crawler->filter('select[name="DiscountCode[plan]"] option')->each(static fn (Crawler $o): string => trim($o->text()));
        self::assertListsSalesPlansOnly(implode("\n", $options), 'discount code plan picker');

        // A code limited to a backing plan before this rule: shown as it is, not silently widened when saved.
        $code = (new DiscountCode())->setCode('TBP-OLD')->setType('fixed')->setValue(1000)->setAppliesTo('all')->setPlan($this->em->find(Plan::class, $this->backing->getId()));
        $this->em->persist($code);
        $this->em->flush();
        $crawler = $client->request('GET', '/admin/discount-code/'.$code->getId().'/edit');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame((string) $this->backing->getId(), $crawler->filter('select[name="DiscountCode[plan]"] option[selected]')->attr('value'));
        self::assertStringNotContainsString('[Trial] Old', implode("\n", $crawler->filter('select[name="DiscountCode[plan]"] option')->each(static fn (Crawler $o): string => $o->text())));
    }

    public function testTheWebResellerPricePickerHidesThem(): void
    {
        $this->setting(WalletSettings::ENABLED, 'true');
        $this->setting(ResellerSettings::ENABLED, 'true');
        $reseller = new Reseller($this->createUser());
        $this->em->persist($reseller);
        $this->em->flush();
        $client = $this->superAdminBrowser();

        $crawler = $client->request('GET', '/admin/resellers/'.$reseller->getId());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $options = $crawler->filter('select[name="plan_id"] option')->each(static fn (Crawler $o): string => trim($o->text()));
        self::assertNotSame([], $options);
        self::assertListsSalesPlansOnly(implode("\n", $options), 'reseller price picker');
    }

    public function testTheWebPanelSetupPlanStepHidesThem(): void
    {
        $client = $this->superAdminBrowser();
        $client->request('GET', '/admin/panel-setup/'.$this->trialInbound->getPanel()->getId().'/plans');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertListsSalesPlansOnly((string) $client->getResponse()->getContent(), 'panel setup plans');
    }

    public function testTheBulkPriceAdjustmentAndItsCommandLeaveThemOut(): void
    {
        // A fixed increase is what would give a backing plan a price (a percent of 0 stays 0).
        $preview = self::getContainer()->get(PlanPriceAdjustmentService::class)->preview('increase', 'price', null, 1000);
        self::assertSame(['Gold', '[Trial] Lookalike'], array_values(array_unique(array_map(static fn (array $c): string => $c['plan']->getTitle(), $preview['changes']))));

        // Scoped to chosen plans (the Mini App's ticked plans): a backing plan id sent anyway is not taken.
        $scoped = self::getContainer()->get(PlanPriceAdjustmentService::class)->preview('increase', 'price', null, 1000, new \App\Shop\Application\PlanScope(false, [(int) $this->backing->getId(), (int) $this->gold->getId()]));
        self::assertSame(['Gold'], array_map(static fn (array $c): string => $c['plan']->getTitle(), $scoped['changes']));

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:plans:adjust-prices'));
        $tester->execute(['--direction' => 'increase', '--field' => 'price', '--amount' => '1000', '--dry-run' => true]);
        self::assertStringContainsString('Plan #'.$this->gold->getId().' (', $tester->getDisplay());
        self::assertStringNotContainsString('Plan #'.$this->backing->getId().' (', $tester->getDisplay());
        self::assertStringNotContainsString('Plan #'.$this->orphan->getId().' (', $tester->getDisplay());
    }

    public function testTheInboundPagesUsedByPlansListsSalesPlansOnly(): void
    {
        $plans = array_column(self::getContainer()->get(PlanInboundUsage::class)->plansUsing((int) $this->trialInbound->getId()), 'planTitle');

        self::assertSame(['Gold', '[Trial] Lookalike'], $plans);
    }

    public function testTheBotAdminPlanListHidesThem(): void
    {
        $this->pressButton(self::ENV_ADMIN, 'admin.pl:l:0');

        $sent = implode("\n", $this->sentTexts()).json_encode($this->sentKeyboards(), JSON_UNESCAPED_UNICODE);
        self::assertListsSalesPlansOnly($sent, 'bot plan list');
    }

    public function testTheShopCatalogNeverListsOneEvenActivatedAndPricedBySql(): void
    {
        $this->activateBackingPlansBySql();

        $titles = array_map(static fn (Plan $p): string => $p->getTitle(), self::getContainer()->get(PlanCatalog::class)->forSale());

        self::assertSame(['Gold', '[Trial] Lookalike'], $titles);
    }

    public function testTheResellerOffersCountSalesPlansOnly(): void
    {
        $this->setting(WalletSettings::ENABLED, 'true');
        $this->setting(ResellerSettings::ENABLED, 'true');
        $user = $this->createUser();
        $this->em->persist(new Reseller($user));
        $this->em->flush();
        // Only the backing plans are active: there is no plan to offer at all (not "plans that are not for sale").
        $this->em->getConnection()->executeStatement('UPDATE plan SET is_active = 0');
        $this->activateBackingPlansBySql();
        $user = $this->em->find(\App\Entity\User::class, $user->getId()) ?? self::fail('user');

        $offers = self::getContainer()->get(ResellerCheckout::class)->newServiceOffers($user);

        self::assertSame([], $offers['offers']);
        self::assertSame(ResellerCheckout::OFFERS_NO_PLANS, $offers['reason']);
    }

    public function testTheMiniAppDiscountFormRefusesABackingPlanButKeepsACodesOwn(): void
    {
        $input = self::getContainer()->get(CatalogInput::class);
        $base = ['code' => 'TBP1', 'type' => 'fixed', 'value' => '5000', 'appliesTo' => 'all'];

        self::assertSame(['plan' => 'admin.form.choice'], $input->toDiscount(new DiscountCode(), $base + ['plan' => (string) $this->backing->getId()]));
        self::assertSame([], $input->toDiscount(new DiscountCode(), $base + ['plan' => (string) $this->lookalike->getId()]));
        $limited = (new DiscountCode())->setPlan($this->em->find(Plan::class, $this->backing->getId()));
        self::assertSame([], $input->toDiscount($limited, $base + ['plan' => (string) $this->backing->getId()]), 'the code keeps the plan it has');
    }

    private function setting(string $key, string $value): void
    {
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['keyName' => $key]) ?? (new Setting())->setKeyName($key)->setType('string');
        $setting->setValue($value);
        $this->em->persist($setting);
        $this->em->flush();
    }
}
