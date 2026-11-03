<?php

declare(strict_types=1);

namespace App\Tests\Shop\TrialBacking;

use App\Entity\DiscountCode;
use App\Entity\Plan;
use App\Entity\Reseller;
use App\Entity\ResellerPlanPrice;
use App\Entity\TrialPlan;
use App\Shop\Application\Catalog\TrialBackingPlans;
use App\Shop\Application\Catalog\TrialPlanAdminService;
use App\Shop\Application\Catalog\TrialPlanDeletion;
use App\Shop\Application\Checkout\TrialCheckout;
use App\Shop\Application\TrialClaimResult;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\TrialBackingFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A trial plan's lifecycle from the trial area (docs/design/trial-backing-plans.md): deactivating it hides it from
 * customers; deleting it deletes its backing plan when nothing refers to it (wholesale rules removed, discount codes
 * limited to it deactivated), else keeps the backing plan inactive, unpriced and hidden, and the admin is told why.
 */
final class TrialPlanLifecycleTest extends IntegrationTestCase
{
    use TrialBackingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_audit_log');
        $this->setUpTrialBacking();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM trial_claim');
        $this->em->getConnection()->executeStatement('DELETE FROM trial_plan');
        parent::tearDown();
    }

    public function testDeactivatingATrialPlanHidesItFromCustomers(): void
    {
        $checkout = self::getContainer()->get(TrialCheckout::class);
        self::assertContains($this->trial->getId(), array_map(static fn (TrialPlan $t): ?int => $t->getId(), $checkout->activePlans()));

        self::assertTrue(self::getContainer()->get(TrialPlanAdminService::class)->setActive($this->trial, false));

        self::assertNotContains($this->trial->getId(), array_map(static fn (TrialPlan $t): ?int => $t->getId(), $checkout->activePlans()));
        self::assertNull($checkout->activePlan((int) $this->trial->getId()));
        $account = $this->createUser()->getTelegramAccount() ?? self::fail('account');
        self::assertSame(TrialClaimResult::STATUS_INACTIVE, $checkout->claim($account, $this->trial)->status);
        self::assertSame(1, $this->ordersOn($this->backing), 'no new trial order');
    }

    public function testDeletingAClaimedTrialKeepsItsBackingPlanHiddenAndSaysWhy(): void
    {
        $this->activateBackingPlansBySql(); // even an active, priced one (by SQL) ends inactive and unpriced
        $backingId = (int) $this->backing->getId();
        $client = $this->superAdminBrowser();

        $this->deleteOnTheWeb($client, (int) $this->trial->getId());

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('پلن پنهانش «[Trial] Gift» نگه داشته شد، چون 1 سفارش و 1 سرویس به آن ارجاع دارند', $content);
        self::assertSame(0, $this->sqlCount('SELECT COUNT(*) FROM trial_plan'));
        $row = $this->em->getConnection()->fetchAssociative('SELECT is_active, price FROM plan WHERE id = ?', [$backingId]);
        self::assertSame(['is_active' => 0, 'price' => 0], array_map('intval', $row ?: []));
        $plan = $this->em->find(Plan::class, $backingId) ?? self::fail('kept');
        self::assertTrue(self::getContainer()->get(TrialBackingPlans::class)->isBacking($plan), 'still known as a backing plan, by its trial order');
        $client->request('GET', '/admin/plan');
        self::assertListsSalesPlansOnly((string) $client->getResponse()->getContent(), 'web plan list after the delete');
        self::assertSame(['trial_plan.backing_plan_kept'], $this->auditActions('trial_plan.backing_plan_%'));
    }

    public function testDeletingATrialWhoseBackingPlanNothingRefersToDeletesItToo(): void
    {
        [$trial, $plan] = $this->trialWithAnUnreferencedBackingPlan();
        $reseller = new Reseller($this->createUser());
        $this->em->persist($reseller);
        $this->em->persist((new ResellerPlanPrice($reseller, $plan))->useFixedAmount(1000, null));
        $code = (new DiscountCode())->setCode('ONLYTRIAL')->setType(DiscountCode::TYPE_PERCENT)->setValue(10)->setIsActive(true)->setPlan($plan);
        $this->em->persist($code);
        $this->em->flush();
        $planId = (int) $plan->getId();
        $client = $this->superAdminBrowser();

        $this->deleteOnTheWeb($client, (int) $trial->getId());

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('پلن پنهانش «[Trial] Unused» هم همراهش حذف شد', $content);
        self::assertStringContainsString('غیرفعال شدند: ONLYTRIAL', $content);
        self::assertSame(0, $this->sqlCount('SELECT COUNT(*) FROM plan WHERE id = ?', [$planId]));
        self::assertSame(0, $this->sqlCount('SELECT COUNT(*) FROM reseller_plan_price WHERE plan_id = ?', [$planId]));
        self::assertSame(0, $this->sqlCount("SELECT COUNT(*) FROM discount_code WHERE code = 'ONLYTRIAL' AND is_active = 1"), 'not widened to every plan');
        self::assertSame(1, $this->sqlCount('SELECT COUNT(*) FROM trial_plan'), 'the other trial plan stays');
        self::assertSame(['trial_plan.backing_plan_deleted'], $this->auditActions('trial_plan.backing_plan_%'));
    }

    public function testDeletingATrialNeverClaimedHasNoBackingPlanToDecideAbout(): void
    {
        $trial = (new TrialPlan())->setTitle('Never')->setIsActive(true)->setDurationDays(1)->setTrafficGb(1)->setInbound($this->trialInbound);
        $this->em->persist($trial);
        $this->em->flush();
        $plans = $this->sqlCount('SELECT COUNT(*) FROM plan');

        $deletion = self::getContainer()->get(TrialPlanAdminService::class)->delete($trial);

        self::assertSame(TrialPlanDeletion::NO_BACKING, $deletion->outcome);
        self::assertSame($plans, $this->sqlCount('SELECT COUNT(*) FROM plan'));
        self::assertSame(TrialPlanDeletion::ALREADY_GONE, self::getContainer()->get(TrialPlanAdminService::class)->delete($trial)->outcome, 'a second delete does nothing');
    }

    public function testSalesPlansAreUntouchedByATrialDeletion(): void
    {
        $before = $this->em->getConnection()->fetchAllAssociative('SELECT id, title, price, is_active FROM plan WHERE id IN (?, ?) ORDER BY id', [$this->gold->getId(), $this->lookalike->getId()]);

        self::getContainer()->get(TrialPlanAdminService::class)->delete($this->trial);

        self::assertSame($before, $this->em->getConnection()->fetchAllAssociative('SELECT id, title, price, is_active FROM plan WHERE id IN (?, ?) ORDER BY id', [$this->gold->getId(), $this->lookalike->getId()]));
    }

    /**
     * A trial plan whose backing plan no order refers to (e.g. its claims' orders were cleaned up).
     *
     * @return array{TrialPlan, Plan}
     */
    private function trialWithAnUnreferencedBackingPlan(): array
    {
        $plan = $this->createPlan(0, $this->trialInbound)->setTitle('[Trial] Unused')->setIsActive(false);
        $trial = (new TrialPlan())->setTitle('Unused')->setIsActive(true)->setDurationDays(1)->setTrafficGb(1)->setInbound($this->trialInbound)->setBackingPlan($plan);
        $this->em->persist($trial);
        $this->em->flush();

        return [$trial, $plan];
    }

    private function deleteOnTheWeb(KernelBrowser $client, int $trialId): void
    {
        $index = $client->request('GET', '/admin/trial-plan');
        $token = $index->filter('input[name="token"]');
        $client->request('POST', '/admin/trial-plan/'.$trialId.'/delete', ['token' => $token->count() > 0 ? (string) $token->attr('value') : '']);
        self::assertTrue($client->getResponse()->isRedirect(), (string) $client->getResponse()->getStatusCode());
        $client->followRedirect();
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->em->clear();
    }

    /** @param list<mixed> $params */
    private function sqlCount(string $sql, array $params = []): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql, $params);
    }

    private function ordersOn(Plan $plan): int
    {
        return $this->sqlCount('SELECT COUNT(*) FROM `order` WHERE plan_id = ?', [$plan->getId()]);
    }

    /** @return list<string> */
    private function auditActions(string $like): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT action FROM admin_audit_log WHERE action LIKE ? ORDER BY id', [$like]));
    }
}
