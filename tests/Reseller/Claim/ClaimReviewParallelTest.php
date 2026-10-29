<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Entity\VpnService;
use App\Reseller\Application\ServiceOwnership;
use App\Security\AdminActor;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletReconciler;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Races of the S2 review fixes with real PHP processes and database connections (MariaDB / InnoDB):
 *
 * - B2: a claim against an admin's "Return to reseller", many rounds: never an unhandled database error (a deadlock or
 *   lock wait is retried, then "busy"), and the end state always consistent (WalletReconciler's claim checks).
 * - B3: the same reset button delivered twice at once rotates once; a reset against a return to the reseller is
 *   serialized by the service row: the ex-claimer never rotates a service that was already returned.
 * - M1: the in-flight check of a claim waits for a settlement step that holds the order row and sees its outcome.
 */
#[Group('subprocess')]
final class ClaimReviewParallelTest extends IntegrationTestCase
{
    use ClaimFixtures;
    use SubprocessAssertions;

    private const ROUNDS = 25;

    private ?string $stateFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
    }

    protected function tearDown(): void
    {
        if (null !== $this->stateFile) {
            @unlink($this->stateFile);
        }
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testAClaimAgainstAReturnToTheResellerManyRounds(): void
    {
        $this->fund(100_000_000);
        $rounds = [];
        for ($i = 0; $i < self::ROUNDS; ++$i) {
            $serviceId = 0 === $i ? (int) $this->sold->getId() : $this->buyAnother();
            // Claimed by B (so it can be returned), then an admin link of B's for C, previewed by C.
            $first = (string) (9100 + $i);
            $second = (string) (9200 + $i);
            $this->claimWith($this->makeLink($serviceId)->token, $first);
            $this->em->clear();
            $service = $this->em->find(VpnService::class, $serviceId) ?? self::fail('service gone');
            $link = $this->claimLinks()->createForAdmin($service, AdminActor::environmentBotAdmin('990001'));
            $preview = $this->claims()->preview($link->token, $this->claimer($second), $second);
            $rounds[] = ['claimId' => $preview->claimId, 'ticket' => $preview->ticket, 'telegramId' => $second, 'serviceId' => $serviceId, 'first' => $first, 'claimDelayMs' => random_int(0, 40), 'returnDelayMs' => random_int(0, 40)];
            self::getContainer()->get('cache.rate_limiter')->clear();
        }
        $file = (string) tempnam(sys_get_temp_dir(), 'claim-rounds');
        file_put_contents($file, json_encode($rounds, JSON_THROW_ON_ERROR));
        $t0 = sprintf('%.6F', microtime(true) + 5.0);

        try {
            [$claims, $returns] = $this->runInParallel([
                ['claim-rounds.php', 'claim', $file, $t0, '0.3'],
                ['claim-rounds.php', 'return', $file, $t0, '0.3'],
            ]);
        } finally {
            @unlink($file);
        }
        $claims = json_decode($claims, true, 512, JSON_THROW_ON_ERROR);
        $returns = json_decode($returns, true, 512, JSON_THROW_ON_ERROR);

        $seen = [];
        foreach ($rounds as $i => $round) {
            [$claim, $return] = [$claims[$i], $returns[$i]];
            $seen[$claim.'/'.$return] = ($seen[$claim.'/'.$return] ?? 0) + 1;
            self::assertContains($claim, ['claimed', 'claim_unavailable', 'service_busy'], 'round '.$i.': '.$claim);
            self::assertContains($return, ['returned', 'service_busy'], 'round '.$i.': '.$return);
            $owner = $this->ownerOf($this->em->find(VpnService::class, $round['serviceId']) ?? self::fail('service gone'));
            $expected = match (true) {
                'returned' === $return => $this->user->getId(),
                'claimed' === $claim => $this->claimer($round['telegramId'])->getId(),
                default => $this->claimer($round['first'])->getId(),
            };
            self::assertSame($expected, $owner, sprintf('round %d (%s / %s)', $i, $claim, $return));
        }
        fwrite(STDERR, 'claim/return outcomes: '.json_encode($seen)."\n");
        // Nothing here is in settlement, so "busy" only comes from deadlocks / lock waits that kept failing. With the
        // old lock order (the open link locked through its unique index) a quarter to half of the rounds deadlocked.
        $busy = count(array_filter([...$claims, ...$returns], static fn (string $o): bool => 'service_busy' === $o));
        self::assertLessThan(3, $busy, 'claim and return keep one lock order: they do not deadlock');
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
        self::assertSame(0, $this->sqlInt("SELECT COUNT(*) FROM service_claim WHERE status = 'open'"), 'no link left open: claimed or revoked by the return');
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
    public function testTheSameResetButtonDeliveredTwiceAtOnceRotatesOnce(): void
    {
        $this->claimWith($this->makeLink()->token, '8701');
        $this->sharePanelState();
        $nonce = $this->nonce();
        $at = $this->startAt();

        $outcomes = $this->runInParallel([
            ['claim-reset.php', '8701', (string) $this->sold->getId(), $nonce, $at],
            ['claim-reset.php', '8701', (string) $this->sold->getId(), $nonce, $at],
        ], ['TEST_PANEL_ROTATE_DELAY_MS' => '800']);

        sort($outcomes);
        self::assertSame([ServiceOwnership::RESET_ALREADY_DONE, ServiceOwnership::RESET_DONE], $outcomes);
        $row = $this->row('vpn_service', (int) $this->sold->getId());
        self::assertSame(1, $this->driver()->rotationsOnPanel((string) $row['remote_id']));
        self::assertSame($row['client_uuid'], $this->driver()->uuidOnPanel((string) $row['remote_id']), 'panel and database agree');
    }

    public function testAResetAndThenAReturnStartedDuringIt(): void
    {
        $this->claimWith($this->makeLink()->token, '8701');
        $this->sharePanelState();
        $nonce = $this->nonce();
        $at = $this->startAt();

        [$reset, $return] = $this->runInParallel([
            ['claim-reset.php', '8701', (string) $this->sold->getId(), $nonce, $at],
            ['claim-return.php', (string) $this->sold->getId(), sprintf('%.6F', (float) $at + 0.5)],
        ], ['TEST_PANEL_ROTATE_DELAY_MS' => '2000']);

        // The return waited for the reset (the service row): the rotation happened while the claimer owned it.
        self::assertSame([ServiceOwnership::RESET_DONE, 'returned'], [$reset, $return]);
        $row = $this->row('vpn_service', (int) $this->sold->getId());
        self::assertSame($this->user->getId(), (int) $row['user_id']);
        self::assertSame($row['client_uuid'], $this->driver()->uuidOnPanel((string) $row['remote_id']));
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM service_ownership_event WHERE actor_type = 'admin'"));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    public function testAReturnAndThenAResetStartedDuringIt(): void
    {
        $this->claimWith($this->makeLink()->token, '8701');
        $this->sharePanelState();
        $nonce = $this->nonce();
        $before = $this->row('vpn_service', (int) $this->sold->getId());
        $at = $this->startAt();

        [$return, $reset] = $this->runInParallel([
            ['claim-return.php', (string) $this->sold->getId(), $at],
            ['claim-reset.php', '8701', (string) $this->sold->getId(), $nonce, sprintf('%.6F', (float) $at + 0.05)],
        ], ['TEST_PANEL_ROTATE_DELAY_MS' => '2000']);

        self::assertSame('returned', $return);
        $row = $this->row('vpn_service', (int) $this->sold->getId());
        self::assertSame($this->user->getId(), (int) $row['user_id']);
        if (ServiceOwnership::RESET_DONE === $reset) {
            // The reset got the service row first: it rotated while the claimer still owned it, then the return ran.
            self::assertSame($row['client_uuid'], $this->driver()->uuidOnPanel((string) $row['remote_id']));
        } else {
            // The return got there first: the ex-claimer may not touch the reseller's service.
            self::assertSame('not_found', $reset);
            self::assertSame($before['client_uuid'], $row['client_uuid']);
            self::assertSame($before['client_uuid'], $this->driver()->uuidOnPanel((string) $row['remote_id']));
            self::assertSame(0, $this->driver()->rotationsOnPanel((string) $row['remote_id']));
        }
    }

    /**
     * M1, two sessions: a settlement step holds the renewal's order row (paid, not committed yet) while a claim runs.
     * The claim's in-flight check waits for it and sees "paid": busy. A plain read would have seen "waiting for
     * payment" and handed the service over in the middle of its settlement.
     */
    public function testTheInFlightCheckWaitsForASettlementStepAndSeesIt(): void
    {
        $plan = $this->sold->getOrder()?->getPlan() ?? self::fail('no plan');
        $renewal = $this->createOrder($this->user, $plan, OrderType::RENEWAL, 50000, $this->sold);
        $renewal->setStatus(OrderStatus::WAITING_PAYMENT);
        $this->em->flush();
        $preview = $this->claims()->preview($this->makeLink()->token, $this->claimer('8701'), '8701');

        $settlement = DriverManager::getConnection($this->em->getConnection()->getParams());
        $settlement->beginTransaction();
        $settlement->executeStatement('UPDATE `order` SET status = ? WHERE id = ?', [OrderStatus::PAID, $renewal->getId()]);
        $at = microtime(true) + 4.0;
        $process = $this->start(['claim-accept.php', (string) $preview->claimId, $preview->ticket, '8701', sprintf('%.6F', $at)]);
        // Commit the settlement step 1.5 s after the claim started.
        usleep((int) max(0, ($at + 1.5 - microtime(true)) * 1_000_000));
        $settlement->commit();
        $settlement->close();
        $outcome = $this->finish($process, 'claim-accept.php');
        $tookAtLeast = microtime(true) - $at;

        self::assertSame('service_busy', $outcome);
        self::assertGreaterThan(1.2, $tookAtLeast, 'the claim waited for the settlement step');
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));
        self::assertSame('open', $this->claimStatus($preview->claimId), 'the link is still usable once the renewal is done');
    }

    // ---- helpers ----

    private function buyAnother(): int
    {
        $this->buy();

        return (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM vpn_service');
    }

    /** The "panel" in a file, so the subprocesses and this test see the same clients. */
    private function sharePanelState(): void
    {
        $this->stateFile = (string) tempnam(sys_get_temp_dir(), 'claim-panel');
        $this->driver()->stateFile = $this->stateFile;
        $this->driver()->saveStateNow();
    }

    private function nonce(): string
    {
        $this->em->clear();
        $service = $this->em->find(VpnService::class, $this->sold->getId()) ?? self::fail('service gone');

        return self::getContainer()->get(ServiceOwnership::class)->resetNonce($service);
    }

    /** Both processes start their work at this instant (after their kernels booted). */
    private function startAt(): string
    {
        return sprintf('%.6F', microtime(true) + 4.0);
    }

    /**
     * @param list<list<string>>    $commands script name and arguments, each in its own process
     * @param array<string, string> $env
     *
     * @return list<string> what each printed, in the same order
     */
    private function runInParallel(array $commands, array $env = []): array
    {
        $processes = [];
        foreach ($commands as $arguments) {
            $processes[] = [$arguments[0], $this->start($arguments, $env)];
        }
        $outcomes = [];
        foreach ($processes as [$script, $process]) {
            $outcomes[] = $this->finish($process, $script);
        }
        $this->em->clear();

        return $outcomes;
    }

    /**
     * @param list<string>          $arguments script name and arguments
     * @param array<string, string> $env
     */
    private function start(array $arguments, array $env = []): Process
    {
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $script = (string) array_shift($arguments);
        $env = ['APP_ENV' => 'test'] + (null !== $this->stateFile ? ['TEST_PANEL_STATE_FILE' => $this->stateFile] : []) + $env;
        $process = new Process([$php, dirname(__DIR__, 2).'/Support/bin/'.$script, ...$arguments], dirname(__DIR__, 3), $env, null, 300);
        $process->start();

        return $process;
    }

    private function finish(Process $process, string $script): string
    {
        try {
            $process->wait();
        } catch (ProcessTimedOutException) {
            self::fail(sprintf("%s timed out.\n--- stdout ---\n%s\n--- stderr ---\n%s", $script, mb_substr($process->getOutput(), -4000), mb_substr($process->getErrorOutput(), -4000)));
        }

        return self::assertSubprocessClean($process, $script);
    }
}
