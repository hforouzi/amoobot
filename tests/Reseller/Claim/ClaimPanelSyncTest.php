<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Entity\ServiceClaim;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Reseller\Application\ClaimMaintenance;
use App\Reseller\Application\ClaimPanelSync;
use App\Security\AdminActor;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\ConsoleOutputAssertions;
use App\Tests\Support\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The panel update after a claim is best effort and never undoes it: a failure leaves the claim done and the update
 * pending, retried by app:claims:expire (and app:automation:run --only=claims) after a growing delay, given up after
 * ClaimPanelSync::MAX_ATTEMPTS. A panel whose driver cannot set the owner (legacy 3x-ui) is skipped.
 */
final class ClaimPanelSyncTest extends IntegrationTestCase
{
    use ClaimFixtures;
    use ConsoleOutputAssertions;

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

    public function testAPanelFailureDoesNotUndoTheClaimAndIsRetriedLater(): void
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        Clock::set(new MockClock($now));
        $this->driver()->ownerUpdateFailures = 1;

        [$outcome] = $this->claimWith($this->makeLink()->token, '8701');

        self::assertSame('claimed', $outcome);
        self::assertSame($this->claimer('8701')->getId(), $this->ownerOf($this->sold));
        $claim = $this->row('service_claim', (int) $this->em->getConnection()->fetchOne('SELECT id FROM service_claim'));
        self::assertSame([ServiceClaim::SYNC_PENDING, 1, $now->modify('+300 seconds')->format('Y-m-d H:i:s'), null], [$claim['panel_sync_status'], (int) $claim['panel_sync_attempts'], $claim['panel_sync_next_at'], $claim['panel_synced_at']]);
        self::assertSame([], $this->driver()->ownerUpdates);
        self::assertSame(1, $this->sqlInt("SELECT COUNT(*) FROM service_claim WHERE status = 'claimed'"));

        // Not yet due: nothing tried.
        Clock::set(new MockClock($now->modify('+299 seconds')));
        self::assertSame(0, $this->maintenance()->retryPanelSync(100)['tried']);

        // Due: the command tries again and it works.
        Clock::set(new MockClock($now->modify('+301 seconds')));
        $tester = new CommandTester((new Application(self::$kernel))->find('app:claims:expire'));
        self::assertSame(0, $tester->execute([]));
        self::assertConsoleContains('synced 1', $tester->getDisplay());
        $claim = $this->row('service_claim', (int) $claim['id']);
        self::assertSame(ServiceClaim::SYNC_SYNCED, $claim['panel_sync_status']);
        self::assertNotNull($claim['panel_synced_at']);
        self::assertSame(8701, $this->driver()->ownerUpdates[0]['telegramId']);
        self::assertSame(0, $this->maintenance()->retryPanelSync(100)['tried'], 'done once');
    }

    public function testTheDelayGrowsAndTheUpdateIsGivenUpAfterTheLastAttempt(): void
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        Clock::set(new MockClock($now));
        $this->driver()->ownerUpdateFailures = 100;
        $this->claimWith($this->makeLink()->token, '8701');
        $id = (int) $this->em->getConnection()->fetchOne('SELECT id FROM service_claim');

        $delays = [];
        for ($attempt = 2; $attempt <= ClaimPanelSync::MAX_ATTEMPTS; ++$attempt) {
            $next = new \DateTimeImmutable((string) $this->row('service_claim', $id)['panel_sync_next_at']);
            $delays[] = $next->getTimestamp() - $now->getTimestamp();
            $now = $next;
            Clock::set(new MockClock($now));
            self::assertSame(1, $this->maintenance()->retryPanelSync(100)['failed']);
        }
        self::assertSame([300, 600, 1200, 2400, 4800, 9600, 19200, 21600, 21600, 21600, 21600], $delays);
        $row = $this->row('service_claim', $id);
        self::assertSame([ServiceClaim::SYNC_FAILED, ClaimPanelSync::MAX_ATTEMPTS, null], [$row['panel_sync_status'], (int) $row['panel_sync_attempts'], $row['panel_sync_next_at']]);
        Clock::set(new MockClock($now->modify('+1 day')));
        self::assertSame(0, $this->maintenance()->retryPanelSync(100)['tried'], 'given up');
        self::assertSame('claimed', $this->claimStatus($id), 'the claim stands');
    }

    public function testALegacyPanelIsSkipped(): void
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('legacy')->setBaseUrl('https://legacy.panel.test/');
        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId('1')->setTitle('legacy inbound');
        $this->em->persist($panel);
        $this->em->persist($inbound);
        $this->em->flush();
        $customer = $this->createUser('8680');
        $service = $this->createActiveService($customer, $inbound)->setRemoteId('sanaei_3xui|panel=1|inbound=1|remoteInbound=1|uuid=u|email=e');
        $this->em->flush();

        $link = $this->claimLinks()->createForAdmin($service, AdminActor::environmentBotAdmin('990001'));
        $this->claimWith($link->token, '8701');

        $row = $this->row('service_claim', (int) $link->claim->getId());
        self::assertSame(ServiceClaim::SYNC_SKIPPED, $row['panel_sync_status']);
        self::assertSame($this->claimer('8701')->getId(), $this->ownerOf($service));
        self::assertSame(0, $this->maintenance()->retryPanelSync(100)['tried']);
    }

    public function testTheAutomationTaskRunsTheSameMaintenance(): void
    {
        $link = $this->makeLink();
        $this->em->getConnection()->executeStatement('UPDATE service_claim SET expires_at = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
        self::assertSame(0, $tester->execute(['--only' => 'claims']));
        self::assertSame('expired', $this->claimStatus((int) $link->claim->getId()));

        $tester = new CommandTester((new Application(self::$kernel))->find('app:claims:expire'));
        self::assertSame(2, $tester->execute(['--limit' => "5\n"]), 'a limit with a suffix is refused (Command::INVALID)');
    }

    private function maintenance(): ClaimMaintenance
    {
        return self::getContainer()->get(ClaimMaintenance::class);
    }
}
