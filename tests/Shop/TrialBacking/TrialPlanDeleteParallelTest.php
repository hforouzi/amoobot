<?php

declare(strict_types=1);

namespace App\Tests\Shop\TrialBacking;

use App\Entity\TrialPlan;
use App\Shop\Application\Catalog\TrialBackingPlans;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\ParallelStart;
use App\Tests\Support\SubprocessAssertions;
use App\Tests\Support\TrialBackingFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Real races (two PHP processes, two database connections) between a customer claiming a trial and an admin deleting
 * its trial plan (lock order: the trial_plan row, then the backing plan row; TrialAccountService,
 * TrialPlanAdminService::delete()). Whatever the order: no foreign key error, a claim that got its order keeps the
 * backing plan (inactive, unpriced, hidden), and no "[Trial] ..." plan is left that is neither a trial's backing plan
 * nor known by a trial order (it would show up in the sales area).
 */
#[Group('subprocess')]
final class TrialPlanDeleteParallelTest extends IntegrationTestCase
{
    use ParallelStart;
    use SubprocessAssertions;
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

    /**
     * @return iterable<string, array{}>
     */
    public static function runs(): iterable
    {
        foreach (range(1, 3) as $run) {
            yield '#'.$run => [];
        }
    }

    #[DataProvider('runs')]
    public function testDeletingWhileAClaimIsBeingProvisioned(): void
    {
        $trial = $this->trialPlan('Fresh');
        $account = (string) ($this->createUser()->getTelegramAccount()?->getId() ?? self::fail('account'));
        $this->em->flush();

        // The claim's order is committed at once; its panel call takes 2.5 s; the delete comes 0.8 s in.
        [$claim, $delete] = $this->runInParallel([
            ['trial-claim.php', $account, (string) $trial->getId(), $this->startAt()],
            ['trial-plan-delete.php', (string) $trial->getId(), $this->startAt(0.8)],
        ], ['TEST_PANEL_DELAY_MS' => '2500']);

        self::assertSame('success', $claim, 'the customer still gets the service');
        self::assertMatchesRegularExpression('/^backing_kept:1:[01]$/', $delete, 'it saw the claim\'s order');
        $plan = $this->planTitled('[Trial] Fresh');
        self::assertSame(['is_active' => 0, 'price' => 0], $plan);
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM vpn_service s JOIN `order` o ON o.id = s.order_id WHERE o.type = 'trial' AND o.metadata LIKE '%Fresh%'"));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM trial_plan WHERE id = ?', [$trial->getId()]));
        $this->assertEveryTrialTitledPlanIsKnown();
    }

    #[DataProvider('runs')]
    public function testAClaimAndADeleteAtTheSameInstant(): void
    {
        // A trial plan whose backing plan nothing refers to yet: the delete would remove it, the claim would use it.
        $plan = $this->createPlan(0, $this->trialInbound)->setTitle('[Trial] Race')->setIsActive(false);
        $trial = $this->trialPlan('Race')->setBackingPlan($plan);
        $account = (string) ($this->createUser()->getTelegramAccount()?->getId() ?? self::fail('account'));
        $this->em->flush();
        $at = $this->startAt();

        [$claim, $delete] = $this->runInParallel([
            ['trial-claim.php', $account, (string) $trial->getId(), $at],
            ['trial-plan-delete.php', (string) $trial->getId(), $at],
        ]);

        self::assertContains([$claim, $delete], [['success', 'backing_kept:1:1'], ['success', 'backing_kept:1:0'], ['inactive', 'backing_deleted:0:0']]);
        if ('success' === $claim) {
            self::assertSame(['is_active' => 0, 'price' => 0], $this->planTitled('[Trial] Race'));
        } else {
            self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM plan WHERE title = '[Trial] Race'"));
            self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM `order` WHERE metadata LIKE '%Race%'"), 'nothing created');
        }
        $this->assertEveryTrialTitledPlanIsKnown();
    }

    private function trialPlan(string $title): TrialPlan
    {
        $trial = (new TrialPlan())->setTitle($title)->setIsActive(true)->setDurationDays(1)->setTrafficGb(1)->setInbound($this->trialInbound)->setMaxClaimsPerUser(5);
        $this->em->persist($trial);
        $this->em->flush();

        return $trial;
    }

    /** @return array{is_active: int, price: int} */
    private function planTitled(string $title): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT is_active, price FROM plan WHERE title = ?', [$title]);
        self::assertCount(1, $rows, $title);

        return ['is_active' => (int) $rows[0]['is_active'], 'price' => (int) $rows[0]['price']];
    }

    /** Every "[Trial] ..." plan except the normal lookalike is a backing plan the sales area leaves out. */
    private function assertEveryTrialTitledPlanIsKnown(): void
    {
        $titled = array_map('intval', $this->em->getConnection()->fetchFirstColumn("SELECT id FROM plan WHERE title LIKE '[Trial]%' AND id <> ?", [$this->lookalike->getId()]));
        $known = self::getContainer()->get(TrialBackingPlans::class)->backingAmong($titled);
        sort($titled);
        sort($known);
        self::assertSame($titled, $known);
    }

    /** @param list<mixed> $params */
    private function sqlInt(string $sql, array $params = []): int
    {
        return (int) $this->em->getConnection()->fetchOne($sql, $params);
    }

    /**
     * @param list<list<string>>    $commands script name and arguments, each in its own process
     * @param array<string, string> $env
     *
     * @return list<string> what each printed, in the same order
     */
    private function runInParallel(array $commands, array $env = []): array
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        foreach ($commands as $arguments) {
            $script = (string) array_shift($arguments);
            $process = new Process([$php, dirname(__DIR__, 2).'/Support/bin/'.$script, ...$arguments], dirname(__DIR__, 3), ['APP_ENV' => 'test'] + $env, null, 180);
            $process->start();
            $processes[] = [$script, $process];
        }
        $this->releaseStart(array_column($processes, 1));
        $outcomes = [];
        foreach ($processes as [$script, $process]) {
            try {
                $process->wait();
            } catch (ProcessTimedOutException) {
                self::fail(sprintf("%s timed out.\n--- stdout ---\n%s\n--- stderr ---\n%s", $script, mb_substr($process->getOutput(), -4000), mb_substr($process->getErrorOutput(), -4000)));
            }
            $outcomes[] = self::assertSubprocessClean($process, $script);
        }
        $this->removeStart();
        $this->em->clear();

        return $outcomes;
    }
}
