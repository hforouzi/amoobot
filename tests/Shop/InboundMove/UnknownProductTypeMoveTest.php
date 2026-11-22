<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Application\InboundMoveScope;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PM2a, fail closed: the inbound-moves job (app:automation:run --only=inbound-moves, InboundMoveRunner) skips the move
 * of a service whose product_type has no module: not claimed, no panel call, the move stays planned (so the run stays
 * open), one "unknown_product_type" line is logged. The 'vpn' service of the same run is moved as before
 * (InboundMoveScheduledTest's first scenario), and the exit code is the one it gives.
 */
final class UnknownProductTypeMoveTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em->getConnection()->executeStatement('DELETE FROM admin_user');
        $this->setUpMove();
    }

    public function testTheUnknownTypesMoveIsLeftAloneAndTheVpnServiceIsMoved(): void
    {
        $vpn = (int) $this->movableService(1)->getId();
        $unknown = (int) $this->movableService(2)->getId();
        $this->em->clear();
        $created = self::getContainer()->get(InboundMovePlanner::class)->create($this->managed($this->gold), new InboundMoveScope());
        self::assertIsArray($created, is_string($created) ? $created : '');
        self::assertCount(2, $this->moves());
        $this->em->getConnection()->executeStatement("UPDATE vpn_service SET product_type = 'x' WHERE id = ?", [$unknown]);
        $unknownMove = $this->moveOf($unknown);
        $unknownEmail = (string) $this->serviceRow($unknown)['client_email'];
        $this->fake->requests = [];
        $logger = self::getContainer()->get(RecordingLogger::class);
        $logger->records = [];

        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:automation:run'));
        $code = $tester->execute(['--only' => 'inbound-moves']);
        $this->em->clear();

        self::assertSame(0, $code, $tester->getDisplay());
        self::assertSame('switched', $this->moveOf($vpn)['state'], 'the vpn service is moved');
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow($vpn)['inbound_id']);
        self::assertSame($unknownMove, $this->moveOf($unknown), 'not claimed, not touched');
        self::assertSame((string) $this->de->getId(), (string) $this->serviceRow($unknown)['inbound_id']);
        self::assertNotSame('', $unknownEmail);
        self::assertStringNotContainsString($unknownEmail, implode("\n", $this->requestLog()), 'its client was never asked for');
        self::assertSame('running', $this->runs()[0]['status'], 'its move is still due: the run stays open');
        self::assertSame([sprintf('unknown_product_type service_id=%d type=x', $unknown)], $this->unknownLines($logger));
    }

    /** @return list<string> read through a method: PHPStan keeps the type of a property a test just assigned */
    private function unknownLines(RecordingLogger $logger): array
    {
        return array_values(array_filter(array_map(static fn (array $r): string => $r['message'], $logger->records), static fn (string $m): bool => str_starts_with($m, 'unknown_product_type')));
    }

    /** @return list<string> read through a method: PHPStan keeps the type of a property a test just assigned */
    private function requestLog(): array
    {
        return $this->fake->requestLines();
    }

    /** @return array<string, mixed> */
    private function moveOf(int $serviceId): array
    {
        return $this->em->getConnection()->fetchAssociative('SELECT * FROM service_inbound_move WHERE service_id = ?', [$serviceId]) ?: [];
    }
}
