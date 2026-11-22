<?php

declare(strict_types=1);

namespace App\Tests\Reseller\Claim;

use App\Entity\ServiceClaim;
use App\Entity\VpnService;
use App\Tests\Support\ClaimFixtures;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PM2a, fail closed: the claims job (app:automation:run --only=claims, ClaimMaintenance) leaves alone a link whose
 * service has a product_type without a module: not expired, no panel update; one "unknown_product_type" line. The
 * link of the 'vpn' service in the same batch is expired or synced as before, and the exit code is the one it gives.
 */
final class UnknownProductTypeClaimsTest extends IntegrationTestCase
{
    use ClaimFixtures;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClaims();
        $this->logger = self::getContainer()->get(RecordingLogger::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownClaims();
        parent::tearDown();
    }

    public function testAnExpiredLinkOfAnUnknownTypeStaysOpenAndTheVpnOneExpires(): void
    {
        $unknownService = $this->secondService();
        $vpnLink = (int) $this->makeLink()->claim->getId();
        $unknownLink = (int) $this->makeLink((int) $unknownService->getId())->claim->getId();
        $this->em->getConnection()->executeStatement('UPDATE service_claim SET expires_at = ?', [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        $this->em->getConnection()->executeStatement("UPDATE vpn_service SET product_type = 'x' WHERE id = ?", [$unknownService->getId()]);

        self::assertSame(0, $this->claimsJob());

        self::assertSame('expired', $this->claimStatus($vpnLink));
        self::assertSame('open', $this->claimStatus($unknownLink), 'left alone');
        self::assertSame([$this->line($unknownService)], $this->unknownLines());
    }

    public function testAPendingPanelUpdateOfAnUnknownTypeIsNotTriedAndTheVpnOneIsSynced(): void
    {
        $now = new \DateTimeImmutable('2026-11-01 10:00:00');
        Clock::set(new MockClock($now));
        $unknownService = $this->secondService();
        $this->driver()->ownerUpdateFailures = 2;
        $vpnLink = $this->makeLink();
        $unknownLink = $this->makeLink((int) $unknownService->getId());
        self::assertSame('claimed', $this->claimWith($vpnLink->token, '8701')[0]);
        self::assertSame('claimed', $this->claimWith($unknownLink->token, '8702')[0]);
        $this->em->getConnection()->executeStatement("UPDATE vpn_service SET product_type = 'x' WHERE id = ?", [$unknownService->getId()]);
        $before = $this->row('service_claim', (int) $unknownLink->claim->getId());
        self::assertSame(ServiceClaim::SYNC_PENDING, $before['panel_sync_status']);
        $this->driver()->ownerUpdates = [];
        Clock::set(new MockClock($now->modify('+1 hour')));

        self::assertSame(0, $this->claimsJob());

        self::assertSame(ServiceClaim::SYNC_SYNCED, $this->row('service_claim', (int) $vpnLink->claim->getId())['panel_sync_status']);
        self::assertSame($before, $this->row('service_claim', (int) $unknownLink->claim->getId()), 'not tried: no attempt used');
        self::assertSame([8701], array_map(static fn (array $update): int => (int) $update['telegramId'], $this->driver()->ownerUpdates), 'one panel update, for the vpn service');
        self::assertSame([$this->line($unknownService)], $this->unknownLines());
    }

    /** A second service the reseller bought, so each test has two links of the same owner. */
    private function secondService(): VpnService
    {
        $this->buy();
        $this->em->clear();

        return $this->em->getRepository(VpnService::class)->findOneBy(['soldByReseller' => $this->reseller->getId()], ['id' => 'DESC']) ?? self::fail('no second service');
    }

    private function claimsJob(): int
    {
        $this->logger->records = [];
        $this->em->clear();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:automation:run'));
        $code = $tester->execute(['--only' => 'claims']);
        $this->em->clear();

        return $code;
    }

    private function line(VpnService $service): string
    {
        return sprintf('unknown_product_type service_id=%d type=x', (int) $service->getId());
    }

    /** @return list<string> */
    private function unknownLines(): array
    {
        return array_values(array_filter(array_map(static fn (array $r): string => $r['message'], $this->logger->records), static fn (string $m): bool => str_starts_with($m, 'unknown_product_type')));
    }
}
