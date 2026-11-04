<?php

declare(strict_types=1);

namespace App\Tests\Shop\TrialBacking;

use App\Entity\Reseller;
use App\Entity\ResellerPlanPrice;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\TrialBackingFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:trial-plans:backing-audit: lists the backing plans (by relation, not by title), changes nothing without --apply,
 * fixes active / priced ones and removes their wholesale rules with --apply, finds nothing the second time, and leaves
 * a normal plan titled "[Trial] ..." alone (only noted).
 */
final class TrialBackingPlansAuditCommandTest extends IntegrationTestCase
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

    public function testADryRunListsAndChangesNothing(): void
    {
        $this->breakThem();
        $before = $this->plans();

        $output = $this->audit([]);

        self::assertStringContainsString('dry run', $output);
        self::assertStringContainsString('[Trial] Gift', $output);
        self::assertStringContainsString('#'.$this->trial->getId().' Gift', $output);
        self::assertStringContainsString('[Trial] Old', $output);
        self::assertStringContainsString('- (deleted)', $output);
        self::assertStringContainsString('active, priced, 1 wholesale rule(s)', $output);
        self::assertStringContainsString('2 backing plan(s) to fix. Nothing was changed', $output);
        self::assertStringContainsString(sprintf('Plan #%d ([Trial] Lookalike)', $this->lookalike->getId()), $output, 'the lookalike is noted');
        self::assertSame($before, $this->plans());
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM reseller_plan_price'));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM admin_audit_log'));
    }

    public function testApplyFixesThemOnceAndLeavesSalesPlansAlone(): void
    {
        $this->breakThem();
        $sales = $this->salesPlans();

        $output = $this->audit(['--apply' => true]);

        self::assertStringContainsString('Fixed 2 backing plan(s).', $output);
        foreach ([$this->backing, $this->orphan] as $plan) {
            self::assertSame(['is_active' => 0, 'price' => 0, 'price_per_gb' => null, 'price_per_day' => null, 'is_customizable' => 0], $this->plans()[(int) $plan->getId()]);
        }
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM reseller_plan_price'));
        self::assertSame(2, $this->sqlInt("SELECT COUNT(*) FROM admin_audit_log WHERE action = 'trial_plan.backing_plan_fixed'"));
        self::assertSame($sales, $this->salesPlans(), 'Gold and the lookalike are untouched');
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM `order` WHERE plan_id = ?', [$this->orphan->getId()]), 'nothing but wholesale rules is deleted');

        $again = $this->audit(['--apply' => true]);
        self::assertStringContainsString('Nothing to fix.', $again);
        self::assertSame(2, $this->sqlInt('SELECT COUNT(*) FROM admin_audit_log'));
    }

    public function testNothingToFixWhenAllIsWell(): void
    {
        self::assertStringContainsString('Nothing to fix.', $this->audit([]));
    }

    /** As a hand-written SQL update or an old bug would have left them: active, priced, with a wholesale rule. */
    private function breakThem(): void
    {
        $this->activateBackingPlansBySql();
        $this->em->getConnection()->executeStatement('UPDATE plan SET is_customizable = 1, price_per_gb = 1000 WHERE id = ?', [$this->orphan->getId()]);
        $reseller = new Reseller($this->createUser());
        $this->em->persist($reseller);
        $this->em->persist((new ResellerPlanPrice($reseller, $this->em->find(\App\Entity\Plan::class, $this->backing->getId()) ?? self::fail('plan')))->useFixedAmount(1000, null));
        $this->em->flush();
    }

    /** @param array<string, mixed> $options */
    private function audit(array $options): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:trial-plans:backing-audit'));
        self::assertSame(0, $tester->execute($options));

        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }

    /** @return array<int, array<string, int|null>> */
    private function plans(): array
    {
        $rows = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT id, is_active, price, price_per_gb, price_per_day, is_customizable FROM plan WHERE id IN (?, ?)', [$this->backing->getId(), $this->orphan->getId()]) as $row) {
            $rows[(int) $row['id']] = array_map(static fn (mixed $v): ?int => null === $v ? null : (int) $v, array_diff_key($row, ['id' => 1]));
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function salesPlans(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT id, title, is_active, price, updated_at FROM plan WHERE id IN (?, ?) ORDER BY id', [$this->gold->getId(), $this->lookalike->getId()]);
    }

    /** @param list<mixed> $params */
    private function sqlInt(string $sql, array $params = []): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql, $params);
    }
}
