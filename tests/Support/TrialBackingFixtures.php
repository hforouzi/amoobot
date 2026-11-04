<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AdminUser;
use App\Entity\Plan;
use App\Entity\TrialPlan;
use App\Entity\VpnInbound;
use App\Shop\Application\TrialAccountService;
use App\Shop\Application\TrialClaimResult;
use App\Shop\Domain\OrderType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Trial backing plans next to sales plans (docs/design/trial-backing-plans.md), for IntegrationTestCase tests:
 * - $trial "Gift" (active) and its backing plan $backing "[Trial] Gift", made by a real claim (one trial order);
 * - $orphan "[Trial] Old": the backing plan of a trial plan deleted earlier, known only by its trial order;
 * - two sales plans: $gold, and $lookalike, a normal plan whose title starts with "[Trial]" (stays a sales plan).
 */
trait TrialBackingFixtures
{
    protected VpnInbound $trialInbound;
    protected TrialPlan $trial;
    protected Plan $backing;
    protected Plan $orphan;
    protected Plan $gold;
    protected Plan $lookalike;

    protected function setUpTrialBacking(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM trial_claim');
        $connection->executeStatement('DELETE FROM trial_plan');
        $this->trialInbound = $this->createTestInbound();
        $this->gold = $this->createPlan(100000, $this->trialInbound)->setTitle('Gold');
        $this->lookalike = $this->createPlan(50000, $this->trialInbound)->setTitle('[Trial] Lookalike');
        $this->trial = (new TrialPlan())->setTitle('Gift')->setIsActive(true)->setDurationDays(1)->setTrafficGb(1)->setInbound($this->trialInbound)->setMaxClaimsPerUser(5);
        $this->em->persist($this->trial);
        $this->em->flush();

        $account = $this->createUser()->getTelegramAccount() ?? self::fail('account');
        $result = self::getContainer()->get(TrialAccountService::class)->claim($account, $this->trial);
        self::assertSame(TrialClaimResult::STATUS_SUCCESS, $result->status, $result->message);
        $this->backing = $this->trial->getBackingPlan() ?? self::fail('backing plan');

        $this->orphan = $this->createPlan(0, $this->trialInbound)->setTitle('[Trial] Old')->setIsActive(false);
        $this->createOrder($this->createUser(), $this->orphan, OrderType::TRIAL, 0);
        $this->em->flush();
    }

    /** Activates and prices the backing plans behind the application's back (as a hand-written SQL update would). */
    protected function activateBackingPlansBySql(int $price = 50000): void
    {
        $this->em->getConnection()->executeStatement('UPDATE plan SET is_active = 1, price = ? WHERE id IN (?, ?)', [$price, $this->backing->getId(), $this->orphan->getId()]);
        $this->em->clear();
    }

    protected function superAdminBrowser(): KernelBrowser
    {
        $admin = (new AdminUser())->setEmail('tbp-'.bin2hex(random_bytes(4)).'@example.test')->setPassword('unused')->setRoles([AdminUser::ROLE_SUPER_ADMIN]);
        $this->em->persist($admin);
        $this->em->flush();
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($admin, 'main');

        return $client;
    }

    protected static function assertListsSalesPlansOnly(string $html, string $where): void
    {
        self::assertStringContainsString('Gold', $html, $where.': a sales plan is listed');
        self::assertStringContainsString('[Trial] Lookalike', $html, $where.': a normal plan called "[Trial] ..." is listed');
        self::assertStringNotContainsString('[Trial] Gift', $html, $where.': the trial\'s backing plan is not listed');
        self::assertStringNotContainsString('[Trial] Old', $html, $where.': the backing plan of a deleted trial is not listed');
    }
}
