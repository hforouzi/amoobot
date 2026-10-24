<?php

declare(strict_types=1);

namespace App\Tests\Reseller;

use App\Reseller\Application\ResellerPricing;
use App\Reseller\Application\ResellerSettings;
use App\Reseller\Domain\ResellerStatus;
use App\Shop\Application\PlanPriceSanityChecker;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ResellerFixtures;

/**
 * PlanPriceSanityChecker names an active reseller's rule that the floor overrides on a plan (only while resellers
 * are on); the plan's own reseller floor price counts too. The Mini App's issues() list is unchanged.
 */
final class ResellerPlanSanityTest extends IntegrationTestCase
{
    use ResellerFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpReseller(2000);
    }

    public function testAClippedRuleIsNamedOnlyWhileResellersAreOn(): void
    {
        $checker = self::getContainer()->get(PlanPriceSanityChecker::class);
        self::assertCount(0, $checker->warnings($this->plan), '20 % of 150,000 is above the 50 % floor');

        $this->planRule($this->plan, 7000);
        $this->newRequest();
        $warnings = $checker->warnings($this->plan);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('45,000', $warnings[0], 'what the rule gives');
        self::assertStringContainsString('75,000', $warnings[0], 'the floor that is charged');
        self::assertSame([], $checker->issues($this->plan), 'the Mini App list is about units only');

        $this->setting(ResellerSettings::ENABLED, 'false');
        $this->newRequest();
        self::assertSame([], $checker->warnings($this->plan));
        $this->setting(ResellerSettings::ENABLED, 'true');

        $this->reseller->setStatus(ResellerStatus::DISABLED);
        $this->em->flush();
        $this->newRequest();
        self::assertSame([], $checker->warnings($this->plan), 'a disabled reseller buys nothing');
    }

    public function testThePlanFloorPriceClipsADefaultDiscount(): void
    {
        $pricing = self::getContainer()->get(ResellerPricing::class);
        self::assertCount(0, $pricing->clippedRules($this->plan));

        $this->plan->setResellerFloorAmount(130000);
        $this->em->flush();
        $this->newRequest();
        $clipped = $pricing->clippedRules($this->plan);
        self::assertCount(1, $clipped);
        self::assertSame(['reseller', 120000, 130000], [$clipped[0]['rule'], $clipped[0]['candidate'], $clipped[0]['floor']]);
    }

    /** What the kernel does between two requests: the per-request warning data is read again. */
    private function newRequest(): void
    {
        self::getContainer()->get(ResellerPricing::class)->reset();
        self::getContainer()->get(PlanPriceSanityChecker::class)->reset();
    }
}
