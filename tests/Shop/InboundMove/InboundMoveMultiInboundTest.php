<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Provisioning\Application\InboundAttachRun;
use App\Provisioning\Application\InboundAttachScheduler;
use App\Shop\Application\MultiInboundSettings;
use App\Tests\Support\IntegrationTestCase;

/**
 * PR P4, T9 of docs/design/plan-inbound-change.md 8.2 and 4.3 step 3: a move and the additional inbounds (M2).
 * - B was an additional inbound of the service: its row is removed (B is the primary now);
 * - A is still one of the plan's additional inbounds: an attached row is added for it (the client stays on A);
 * - a detached row stays detached, never attached again by the move or by the scheduled check;
 * - the scheduled check (InboundAttachScheduler) never reports WITHOUT_PRIMARY: not while the client is already on B and
 *   the service still runs on A (the window between 4.3 step 2 and step 3), not after the switch.
 */
final class InboundMoveMultiInboundTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    private VpnInbound $extra;
    private VpnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
        $this->setting(MultiInboundSettings::ENABLED, 'true', 'boolean');
        $remote = $this->fake->addInbound(['remark' => 'extra', 'protocol' => 'vless', 'port' => 2096]);
        $this->extra = (new VpnInbound())->setPanel($this->managed($this->modernPanel))->setRemoteInboundId((string) $remote)->setTitle('EXTRA')->setProtocol('vless');
        $this->em->persist($this->extra);
        $this->em->flush();

        // Sold on DE-1 with NL-2 as an additional inbound; EXTRA was detached from it (on purpose, by an admin).
        $this->service = $this->planService($this->gold, $this->de, n: 1);
        $this->fake->clients['tg_p1_1']['inboundIds'] = [1, 2];
        $db = $this->em->getConnection();
        $db->insert('vpn_service_inbound', ['service_id' => $this->service->getId(), 'inbound_id' => $this->nl->getId(), 'remote_inbound_id' => '2', 'status' => 'attached', 'attempts' => 1, 'updated_at' => '2026-01-01 00:00:00', 'checked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'reattach_count' => 0]);
        $db->insert('vpn_service_inbound', ['service_id' => $this->service->getId(), 'inbound_id' => $this->extra->getId(), 'remote_inbound_id' => (string) $remote, 'status' => 'detached', 'attempts' => 1, 'updated_at' => '2026-01-01 00:00:00', 'reattach_count' => 0]);

        // Now the plan sells NL-2, with DE-1 (and EXTRA) as additional inbounds.
        $this->sellOn($this->gold, $this->nl);
        $this->managed($this->gold)->setAdditionalInbounds([$this->managed($this->de), $this->managed($this->extra)]);
        $this->em->flush();
    }

    public function testBsRowIsRemovedAnAttachedRowIsAddedForAAndTheDetachedOneStays(): void
    {
        // The window between 4.3 step 2 (client on B) and step 3 (service on B): the check trusts the answer.
        $before = $this->scheduled();
        self::assertSame(0, $before->panelFailures, 'no WITHOUT_PRIMARY: the client is on its primary DE-1');

        $this->fake->requests = [];
        [$code, $output] = $this->command(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame([], $this->attachCalls(), 'already on NL-2: nothing sent');
        self::assertSame((string) $this->nl->getId(), (string) $this->serviceRow((int) $this->service->getId())['inbound_id']);
        self::assertSame([
            [(int) $this->extra->getId(), 'detached'],
            [(int) $this->de->getId(), 'attached'],
        ], $this->rows(), 'NL-2\'s row removed (the primary now), DE-1 attached, EXTRA still detached');
        self::assertSame([1, 2], $this->fake->clients['tg_p1_1']['inboundIds'], 'the old inbound stays attached on the panel');

        $this->fake->requests = [];
        $after = $this->scheduled();
        self::assertSame(0, $after->panelFailures, 'no WITHOUT_PRIMARY after the switch either');
        self::assertSame([0, 0, 0], [$after->attached, $after->foundRemoved, $after->foundAttached]);
        self::assertSame([], $this->attachCalls(), 'the detached EXTRA is never attached again');
        self::assertSame([(int) $this->extra->getId(), 'detached'], $this->rows()[0]);
    }

    public function testAnOldInboundNoLongerInThePlanGetsNoRow(): void
    {
        $this->managed($this->gold)->setAdditionalInbounds([$this->managed($this->extra)]);
        $this->em->flush();

        [$code, $output] = $this->command(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertSame([[(int) $this->extra->getId(), 'detached']], $this->rows(), 'DE-1 is not a plan additional inbound: no row (phase 2 detaches it, P6)');
        self::assertSame(0, $this->scheduled()->panelFailures);
    }

    /** @return list<array{int, string}> the service's additional inbound rows: inbound id, status */
    private function rows(): array
    {
        return array_map(static fn (array $r): array => [(int) $r['inbound_id'], (string) $r['status']], $this->em->getConnection()->fetchAllAssociative('SELECT inbound_id, status FROM vpn_service_inbound WHERE service_id = ? ORDER BY id', [$this->service->getId()]));
    }

    private function scheduled(): InboundAttachRun
    {
        $this->em->getConnection()->executeStatement("UPDATE vpn_service_inbound SET checked_at = '2000-01-01 00:00:00'");
        $this->em->clear();

        return self::getContainer()->get(InboundAttachScheduler::class)->run(500, false, 50, 0);
    }
}
