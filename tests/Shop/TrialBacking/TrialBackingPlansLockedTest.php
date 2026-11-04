<?php

declare(strict_types=1);

namespace App\Tests\Shop\TrialBacking;

use App\Entity\Order;
use App\Entity\Plan;
use App\Entity\Reseller;
use App\Entity\Setting;
use App\Reseller\Application\ResellerAdministration;
use App\Reseller\Application\ResellerSettings;
use App\Security\AdminActor;
use App\Shop\Application\Catalog\PlanAdminService;
use App\Shop\Application\Catalog\TrialBackingPlanLocked;
use App\Shop\Application\Checkout\PlanCatalog;
use App\Shop\Application\Checkout\PlanSaleRule;
use App\Shop\Application\PlanPriceAdjustmentService;
use App\Shop\Domain\OrderType;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\TrialBackingFixtures;
use App\Wallet\Application\WalletSettings;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A trial's backing plan cannot be activated, priced, given wholesale prices, edited or deleted from the sales area,
 * and is never sold (docs/design/trial-backing-plans.md, fix 2). Direct URLs answer "this plan belongs to the trial
 * plan ..." instead of an error page. A normal plan, also one called "[Trial] ...", behaves exactly as before.
 */
final class TrialBackingPlansLockedTest extends IntegrationTestCase
{
    use BotAdminTestTrait;
    use TrialBackingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTrialBacking();
        self::getContainer()->get('translator')->setLocale('en');
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM trial_claim');
        $this->em->getConnection()->executeStatement('DELETE FROM trial_plan');
        parent::tearDown();
    }

    public function testDirectWebUrlsShowTheMessageAndTheTrialPlanNotAnErrorPage(): void
    {
        $client = $this->superAdminBrowser();
        $before = $this->planRow($this->backing);

        foreach (['/admin/plan/%d', '/admin/plan/%d/edit'] as $url) {
            $client->request('GET', sprintf($url, $this->backing->getId()));
            self::assertSame('/admin/trial-plan/'.$this->trial->getId(), $this->redirectPath($client), $url.' goes to its trial plan');
            $client->followRedirect();
            self::assertSame(200, $client->getResponse()->getStatusCode());
            // The web panel speaks Persian by default.
            self::assertStringContainsString('«[Trial] Gift» پلنِ پنهانِ پلن تست «Gift» است', (string) $client->getResponse()->getContent());
        }

        // The list's active switch (a PATCH to edit) and a crafted form post: nothing changes.
        $client->request('PATCH', '/admin/plan/'.$this->backing->getId().'/edit?fieldName=isActive&newValue=true');
        self::assertTrue($client->getResponse()->isRedirect());
        $client->request('POST', '/admin/plan/'.$this->backing->getId().'/edit', ['Plan' => ['title' => 'Free!', 'price' => '1', 'isActive' => '1']]);
        self::assertTrue($client->getResponse()->isRedirect());
        $client->request('POST', '/admin/plan/'.$this->backing->getId().'/delete');
        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame($before, $this->planRow($this->backing));

        // The plan of a deleted trial: the trial plans, with its own message.
        $client->request('GET', '/admin/plan/'.$this->orphan->getId());
        self::assertSame('/admin/trial-plan', $this->redirectPath($client));
        $client->followRedirect();
        self::assertStringContainsString('«[Trial] Old» پلنِ پنهانِ یک پلن تستِ حذف‌شده است', (string) $client->getResponse()->getContent());
    }

    public function testTheTrialPlanPageShowsItsHiddenPlanAsTextWithoutALinkToThePlansArea(): void
    {
        $client = $this->superAdminBrowser();
        $client->request('GET', '/admin/trial-plan/'.$this->trial->getId());

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('[Trial] Gift', $html);
        self::assertStringNotContainsString('/admin/plan/'.$this->backing->getId(), $html);
    }

    public function testEveryPlanAdminServiceWriteRefusesItAndNothingIsWritten(): void
    {
        $plans = self::getContainer()->get(PlanAdminService::class);
        $before = $this->planRow($this->backing);
        foreach ([$this->backing, $this->orphan] as $backing) {
            $plan = $this->em->find(Plan::class, $backing->getId()) ?? self::fail('plan');
            foreach ([
                'activate' => static fn () => $plans->setActive($plan, true),
                'price' => static fn () => $plans->changePrice($plan, 90000),
                'save' => static fn () => $plans->save($plan->setPrice(1)),
                'delete' => static fn () => $plans->delete($plan),
            ] as $what => $call) {
                try {
                    $call();
                    self::fail($what.' was not refused');
                } catch (TrialBackingPlanLocked $e) {
                    self::assertSame($backing->getId(), $e->planId);
                }
            }
            $this->em->clear();
        }
        self::assertSame($before, $this->planRow($this->backing));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan WHERE id = ?', [$this->orphan->getId()]));

        // A normal plan called "[Trial] ..." (no trial order, no trial plan): as before.
        $lookalike = $this->em->find(Plan::class, $this->lookalike->getId()) ?? self::fail('plan');
        self::assertTrue($plans->setActive($lookalike, false));
        self::assertSame(50000, $plans->changePrice($lookalike, 60000));
        $plans->save($lookalike->setDescription('still mine'));
        $plans->delete($lookalike);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM plan WHERE id = ?', [$this->lookalike->getId()]));
    }

    public function testTheBotAnswersWithTheMessageAndChangesNothing(): void
    {
        $before = $this->planRow($this->backing);
        foreach (['admin.pl:v:%d', 'admin.pl:t:%d', 'admin.pl:tk:%d:1', 'admin.pl:pr:%d', 'admin.pl:pk:%d:90000'] as $callback) {
            $this->http->reset();
            $this->pressButton(self::ENV_ADMIN, sprintf($callback, $this->backing->getId()));
            self::assertTrue($this->sentTextContains('پلن تست «Gift»'), $callback.': '.implode(' | ', $this->sentTexts()));
        }
        self::assertSame($before, $this->planRow($this->backing));

        // The normal plan "[Trial] Lookalike" is still managed from the bot.
        $this->http->reset();
        $this->pressButton(self::ENV_ADMIN, 'admin.pl:tk:'.$this->lookalike->getId().':0');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT is_active FROM plan WHERE id = ?', [$this->lookalike->getId()]));
    }

    public function testNoWholesaleRuleForIt(): void
    {
        $this->setting(WalletSettings::ENABLED, 'true');
        $this->setting(ResellerSettings::ENABLED, 'true');
        $reseller = new Reseller($this->createUser());
        $this->em->persist($reseller);
        $this->em->flush();

        try {
            self::getContainer()->get(ResellerAdministration::class)->setPlanPrice($reseller, $this->backing, 'percent', '10', AdminActor::environmentBotAdmin(self::ENV_ADMIN));
            self::fail('a wholesale rule was saved');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('reseller_admin.error.trial_plan', $e->getMessage());
        }

        // The web form (a crafted post: the picker does not offer it): the message, nothing saved.
        $client = $this->superAdminBrowser();
        $crawler = $client->request('GET', '/admin/resellers/'.$reseller->getId());
        $token = (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/admin/resellers/'.$reseller->getId().'/plan-price', ['_token' => $token, 'plan_id' => $this->backing->getId(), 'kind' => 'percent', 'value' => '10']);
        $client->followRedirect();
        self::assertStringContainsString('این پلن متعلق به یک پلن تست است و فروخته نمی‌شود', (string) $client->getResponse()->getContent());
        // A normal plan still gets one.
        $client->request('POST', '/admin/resellers/'.$reseller->getId().'/plan-price', ['_token' => $token, 'plan_id' => $this->lookalike->getId(), 'kind' => 'percent', 'value' => '10']);
        self::assertSame([(int) $this->lookalike->getId()], array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT plan_id FROM reseller_plan_price')));
    }

    public function testTheSaleRuleRefusesItEvenActivatedAndPricedBySql(): void
    {
        $this->activateBackingPlansBySql();
        $rule = self::getContainer()->get(PlanSaleRule::class);
        $catalog = self::getContainer()->get(PlanCatalog::class);
        $backing = $this->em->find(Plan::class, $this->backing->getId()) ?? self::fail('plan');
        $orphan = $this->em->find(Plan::class, $this->orphan->getId()) ?? self::fail('plan');
        $lookalike = $this->em->find(Plan::class, $this->lookalike->getId()) ?? self::fail('plan');

        foreach ([$backing, $orphan] as $plan) {
            self::assertTrue($plan->isActive() && $plan->getPrice() > 0, 'active and priced by SQL');
            self::assertFalse($rule->isSellable($plan));
            self::assertFalse($rule->acceptsOrders($plan));
            self::assertNull($catalog->findForSale((int) $plan->getId()));
            self::assertFalse($rule->acceptsPayment($this->createOrder($this->createUser(), $plan)), 'a new-service order on it is never paid');
        }
        self::assertSame([$lookalike], $rule->sellable([$backing, $lookalike, $orphan]));
        self::assertTrue($rule->acceptsTrial($backing), 'the trial itself still uses it');
        self::assertTrue($rule->isSellable($lookalike));
        self::assertTrue($rule->acceptsOrders($lookalike));
        self::assertSame($lookalike, $catalog->findForSale((int) $lookalike->getId()));
        self::assertTrue($rule->acceptsPayment($this->createOrder($this->createUser(), $lookalike)));
        // Renewals and extra traffic of a trial service are not decided by the plan's sale (as before).
        self::assertTrue($rule->acceptsPayment($this->createOrder($this->createUser(), $backing, OrderType::RENEWAL)));
    }

    public function testABulkPriceChangeListBuiltElsewhereStillCannotPriceIt(): void
    {
        $service = self::getContainer()->get(PlanPriceAdjustmentService::class);
        $backing = $this->em->find(Plan::class, $this->backing->getId()) ?? self::fail('plan');
        $gold = $this->em->find(Plan::class, $this->gold->getId()) ?? self::fail('plan');

        $applied = $service->apply([
            ['plan' => $backing, 'field' => 'price', 'before' => 0, 'after' => 1000],
            ['plan' => $gold, 'field' => 'price', 'before' => 100000, 'after' => 101000],
        ]);

        self::assertSame(1, $applied);
        $this->em->clear();
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT price FROM plan WHERE id = ?', [$this->backing->getId()]));
        self::assertSame(101000, (int) $this->em->getConnection()->fetchOne('SELECT price FROM plan WHERE id = ?', [$this->gold->getId()]));
    }

    public function testPanelSetupIgnoresABackingPlanSentAnyway(): void
    {
        $other = (new \App\Entity\VpnInbound())->setPanel($this->trialInbound->getPanel())->setRemoteInboundId('9')->setTitle('Other');
        $this->em->persist($other);
        $this->em->flush();
        $client = $this->superAdminBrowser();
        $url = '/admin/panel-setup/'.$this->trialInbound->getPanel()->getId().'/plans';
        $token = (string) (new Crawler((string) $client->request('GET', $url)->html()))->filter('input[name="_token"]')->attr('value');

        $client->request('POST', $url, ['_token' => $token, 'plan' => [(string) $this->backing->getId() => (string) $other->getId(), (string) $this->gold->getId() => (string) $other->getId()]]);

        self::assertTrue($client->getResponse()->isRedirect());
        self::assertSame($this->trialInbound->getId(), (int) $this->em->getConnection()->fetchOne('SELECT inbound_id FROM plan WHERE id = ?', [$this->backing->getId()]));
        self::assertSame($other->getId(), (int) $this->em->getConnection()->fetchOne('SELECT inbound_id FROM plan WHERE id = ?', [$this->gold->getId()]));
    }

    public function testTheTrialStillWorksOnItsBackingPlan(): void
    {
        $account = $this->createUser()->getTelegramAccount() ?? self::fail('account');
        $trial = $this->em->find(\App\Entity\TrialPlan::class, $this->trial->getId()) ?? self::fail('trial');

        $result = self::getContainer()->get(\App\Shop\Application\TrialAccountService::class)->claim($account, $trial);

        self::assertSame(\App\Shop\Application\TrialClaimResult::STATUS_SUCCESS, $result->status);
        self::assertSame($this->backing->getId(), $result->order?->getPlan()?->getId(), 'the same backing plan');
        self::assertSame(2, (int) $this->em->getRepository(Order::class)->count(['plan' => $this->backing->getId(), 'type' => OrderType::TRIAL]));
    }

    private function redirectPath(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        self::assertTrue($client->getResponse()->isRedirect(), 'a redirect, not an error page');

        return (string) parse_url((string) $client->getResponse()->headers->get('Location'), PHP_URL_PATH);
    }

    /**
     * @return array<string, mixed>
     */
    private function planRow(Plan $plan): array
    {
        $row = $this->row('plan', (int) $plan->getId());
        unset($row['updated_at']);

        return $row;
    }

    private function setting(string $key, string $value): void
    {
        $setting = $this->em->getRepository(Setting::class)->findOneBy(['keyName' => $key]) ?? (new Setting())->setKeyName($key)->setType('string');
        $setting->setValue($value);
        $this->em->persist($setting);
        $this->em->flush();
    }
}
