<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Reseller\Domain\ClaimStatus;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\SubprocessAssertions;
use App\Wallet\Application\WalletReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Real races (two PHP processes, two database connections, started at the same instant), repeated because one run
 * only proves one interleaving: two people claiming one link, a claim against a revoke, against the expiry sweep, and
 * against a renewal of the service in settlement; the same /start and the same confirm button delivered twice; two
 * links made at once. Whatever the order: at most one owner change with exactly one ownership event, the claim row and
 * the service agree, at most one open link per service, and the wallet still reconciles.
 */
#[Group('subprocess')]
final class ClaimParallelTest extends IntegrationTestCase
{
    use ClaimFixtures;
    use SubprocessAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
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
    public function testTwoPeopleClaimTheSameLinkAtOnce(): void
    {
        $token = $this->makeLink()->token;
        $a = $this->claims()->preview($token, $this->claimer('8701'), '8701');
        $b = $this->claims()->preview($token, $this->claimer('8702'), '8702');
        $at = $this->startAt();

        $outcomes = $this->runInParallel([
            ['claim-accept.php', (string) $a->claimId, $a->ticket, '8701', $at],
            ['claim-accept.php', (string) $b->claimId, $b->ticket, '8702', $at],
        ]);

        $sorted = $outcomes;
        sort($sorted);
        self::assertSame(['claim_unavailable', 'claimed'], $sorted);
        $winner = 'claimed' === $outcomes[0] ? '8701' : '8702';
        self::assertSame($this->claimer($winner)->getId(), $this->ownerOf($this->sold));
        $this->assertOneOwnerChangeAndConsistent($a->claimId);
    }

    #[DataProvider('runs')]
    public function testAClaimRacingARevoke(): void
    {
        $token = $this->makeLink()->token;
        $preview = $this->claims()->preview($token, $this->claimer('8701'), '8701');
        $at = $this->startAt();

        [$claim, $revoke] = $this->runInParallel([
            ['claim-accept.php', (string) $preview->claimId, $preview->ticket, '8701', $at],
            ['claim-revoke.php', (string) $preview->claimId, $at],
        ]);

        self::assertContains([$claim, $revoke], [['claimed', 'not_open'], ['claim_unavailable', 'revoked']]);
        if ('claimed' === $claim) {
            $this->assertOneOwnerChangeAndConsistent($preview->claimId);
        } else {
            self::assertSame(ClaimStatus::REVOKED, $this->claimStatus($preview->claimId));
            $this->assertNothingChanged();
        }
    }

    #[DataProvider('runs')]
    public function testAClaimRacingTheExpirySweep(): void
    {
        $token = $this->makeLink()->token;
        $preview = $this->claims()->preview($token, $this->claimer('8701'), '8701');
        $at = $this->startAt();
        // The link expires at the very second both start.
        $this->em->getConnection()->executeStatement('UPDATE service_claim SET expires_at = ?', [(new \DateTimeImmutable('@'.(int) ceil((float) $at)))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s')]);

        [$claim, $expired] = $this->runInParallel([
            ['claim-accept.php', (string) $preview->claimId, $preview->ticket, '8701', $at],
            ['claims-expire.php', $at],
        ]);

        self::assertContains($claim, ['claimed', 'claim_unavailable']);
        self::assertContains($expired, ['0', '1']);
        if ('claimed' === $claim) {
            self::assertSame('0', $expired, 'a claimed link is never expired afterwards');
            $this->assertOneOwnerChangeAndConsistent($preview->claimId);
        } else {
            self::assertSame(ClaimStatus::EXPIRED, $this->claimStatus($preview->claimId));
            $this->assertNothingChanged();
        }
    }

    #[DataProvider('runs')]
    public function testAClaimRacingARenewalInSettlement(): void
    {
        $token = $this->makeLink()->token;
        $preview = $this->claims()->preview($token, $this->claimer('8701'), '8701');
        $at = $this->startAt();
        $claimAt = sprintf('%.6F', (float) $at + 0.6);

        [$renewal, $claim] = $this->runInParallel([
            ['reseller-renew.php', (string) $this->user->getId(), (string) $this->sold->getId(), $at],
            ['claim-accept.php', (string) $preview->claimId, $preview->ticket, '8701', $claimAt],
        ], ['TEST_PANEL_RENEW_DELAY_MS' => '1500']);

        self::assertSame('confirmed', $renewal, 'the renewal is paid and done either way');
        self::assertContains($claim, ['service_busy', 'claimed']);
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM `order` WHERE type = 'renewal' AND status = 'provisioned'"));
        if ('claimed' === $claim) {
            $this->assertOneOwnerChangeAndConsistent($preview->claimId);
        } else {
            // Refused while the renewal was in settlement; the link stays open and works afterwards.
            self::assertSame(ClaimStatus::OPEN, $this->claimStatus($preview->claimId));
            self::assertSame($this->user->getId(), $this->ownerOf($this->sold));
            $this->claims()->claim($preview->claimId, $preview->ticket, $this->claimer('8701'), '8701');
            $this->assertOneOwnerChangeAndConsistent($preview->claimId);
        }
    }

    public function testTheSameStartAndTheSameConfirmDeliveredTwiceAtOnce(): void
    {
        $token = $this->makeLink()->token;
        $this->claimer('8701');
        $at = $this->startAt();

        $this->runInParallel([
            ['bot-message.php', '8701', '424242', '/start claim_'.$token, $at],
            ['bot-message.php', '8701', '424242', '/start claim_'.$token, $at],
        ]);
        $this->assertNothingChanged();
        self::assertSame(ClaimStatus::OPEN, $this->claimStatus((int) $this->em->getConnection()->fetchOne('SELECT id FROM service_claim')));

        // The confirm button (as the preview built it) delivered twice at once.
        $preview = $this->claims()->preview($token, $this->claimer('8701'), '8701');
        $data = sprintf('cl_ok:%d:%s', $preview->claimId, $preview->ticket);
        $at = $this->startAt();
        $this->runInParallel([
            ['bot-callback.php', '8701', $data, $at],
            ['bot-callback.php', '8701', $data, $at],
        ]);
        self::assertSame($this->claimer('8701')->getId(), $this->ownerOf($this->sold));
        $this->assertOneOwnerChangeAndConsistent($preview->claimId);
    }

    #[DataProvider('runs')]
    public function testTwoLinksMadeAtOnceLeaveOneOpenLink(): void
    {
        $at = $this->startAt();

        $outcomes = $this->runInParallel([
            ['claim-create.php', (string) $this->user->getId(), (string) $this->sold->getId(), $at],
            ['claim-create.php', (string) $this->user->getId(), (string) $this->sold->getId(), $at],
        ]);

        foreach ($outcomes as $outcome) {
            self::assertStringStartsWith('created:', $outcome);
        }
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM service_claim WHERE status = 'open'"));
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM service_claim WHERE status = 'revoked' AND revoke_reason = 'replaced'"));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    private function assertOneOwnerChangeAndConsistent(int $claimId): void
    {
        self::assertSame(ClaimStatus::CLAIMED, $this->claimStatus($claimId));
        self::assertSame(1, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
        $claimer = (int) $this->em->getConnection()->fetchOne('SELECT claimed_by_user_id FROM service_claim WHERE id = ?', [$claimId]);
        self::assertSame($claimer, $this->ownerOf($this->sold));
        self::assertSame($claimer, (int) $this->em->getConnection()->fetchOne('SELECT to_user_id FROM service_ownership_event'));
        self::assertNull($this->row('service_claim', $claimId)['open_service_id']);
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
    }

    private function assertNothingChanged(): void
    {
        self::assertSame($this->user->getId(), $this->ownerOf($this->sold));
        self::assertSame(0, $this->sqlInt('SELECT COUNT(*) FROM service_ownership_event'));
        self::assertSame(['balances' => [], 'payments' => []], self::getContainer()->get(WalletReconciler::class)->report());
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
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $processes = [];
        foreach ($commands as $arguments) {
            $script = (string) array_shift($arguments);
            // Generous: each process boots a kernel (one at a time, boot-test-kernel.php) before its work starts.
            $process = new Process([$php, dirname(__DIR__, 2).'/Support/bin/'.$script, ...$arguments], dirname(__DIR__, 3), ['APP_ENV' => 'test'] + $env, null, 180);
            $process->start();
            $processes[] = [$script, $process];
        }
        $outcomes = [];
        foreach ($processes as [$script, $process]) {
            try {
                $process->wait();
            } catch (ProcessTimedOutException $e) {
                // Say where it stood, so a hang in CI can be diagnosed.
                self::fail(sprintf("%s timed out.\n--- stdout ---\n%s\n--- stderr ---\n%s", $script, mb_substr($process->getOutput(), -4000), mb_substr($process->getErrorOutput(), -4000)));
            }
            $outcomes[] = self::assertSubprocessClean($process, $script);
        }
        $this->em->clear();

        return $outcomes;
    }
}
