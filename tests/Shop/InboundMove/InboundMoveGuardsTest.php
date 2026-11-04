<?php

declare(strict_types=1);

namespace App\Tests\Shop\InboundMove;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Domain\PaymentStatus;
use App\Provisioning\Application\InboundMovePlanner;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\IntegrationTestCase;

/**
 * PR P4, T5 of docs/design/plan-inbound-change.md 8.2 and the scope of 6.2 / 4.4: who is never moved, and why.
 * - another panel, a legacy (not modern) panel and the dummy driver: skipped with the reason, zero panel requests;
 * - a client on neither A nor B: failed, a read only (nothing sent);
 * - scope: active only by default; suspended, expired and trials opt-in; deleted never;
 * - in another unfinished run, moved 3 times within 24 h, a protocol mix: skipped;
 * - an inactive target refuses the run (exit 2);
 * - a sale while a run is open gets the plan's current primary and is not part of the frozen run (5.7).
 */
final class InboundMoveGuardsTest extends IntegrationTestCase
{
    use InboundMoveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMove();
    }

    public function testOtherPanelLegacyAndDummyAreSkippedWithoutAnyRequest(): void
    {
        $otherPanel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('other')->setBaseUrl('https://panel2.example.test:2053/x/')->setPlainApiToken('other-token-0123456789')
            ->recordProbe(SanaeiIntegrationMode::MODERN, 'ok', new \DateTimeImmutable());
        $dummyPanel = (new VpnPanel())->setType('dummy')->setTitle('dummy');
        $this->em->persist($otherPanel);
        $this->em->persist($dummyPanel);
        $this->em->flush();
        $onOther = $this->movableService(1)->setPanel($otherPanel);
        $onDummy = $this->movableService(2)->setPanel($dummyPanel);
        $this->em->flush();
        $this->http->on('panel2.example.test', static fn () => throw new \LogicException('the other panel was called'));
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString(sprintf('Service #%d (active, on DE-1): skipped: %s', $onOther->getId(), InboundMovePlanner::skipText(InboundMovePlanner::SKIP_OTHER_PANEL)), $output);
        self::assertStringContainsString(sprintf('Service #%d (active, on DE-1): skipped: not a real panel', $onDummy->getId()), $output);
        self::assertSame([], $this->fake->requests, 'zero requests');
        self::assertSame([], $this->http->requests('panel2.example.test'));
        self::assertSame([['skipped', 'other_panel'], ['skipped', 'not_real_panel']], array_map(static fn (array $m): array => [$m['state'], $m['skip_reason']], $this->moves()));
        self::assertSame('done', $this->runs()[0]['status']);

        // A legacy panel (the plan's own): every service skipped NOT_MODERN, still no request.
        $legacy = $this->managed($this->modernPanel)->setIntegrationMode(SanaeiIntegrationMode::LEGACY);
        $this->em->flush();
        $onLegacy = $this->planService($this->gold, $this->de, n: 3);
        $this->fake->requests = [];
        [$code, $output] = $this->command(['--apply' => true, '--service' => (string) $onLegacy->getId()]);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('skipped: the panel is not in modern mode (Panels → test connection)', $output);
        self::assertSame([], $this->fake->requests);
        self::assertSame('legacy', $legacy->getIntegrationMode()->value);
    }

    public function testAClientOnNeitherTheOldNorTheNewInboundFailsWithNothingSent(): void
    {
        $service = $this->movableService(1);
        $this->fake->clients['tg_p1_1']['inboundIds'] = [3];
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--apply' => true]);

        self::assertSame(1, $code, $output);
        self::assertSame(['GET panel/api/clients/get/tg_p1_1'], $this->fake->requestLines(), 'a read only');
        self::assertStringContainsString('failed: Not moved: the client is not on the expected inbound', $output);
        $move = $this->moves()[0];
        self::assertSame(['failed', '1'], [$move['state'], (string) $move['attempts']]);
        self::assertNotNull($move['next_attempt_at'], 'backed off');
        self::assertSame((string) $this->de->getId(), (string) $this->serviceRow((int) $service->getId())['inbound_id']);
        self::assertSame('running', $this->runs()[0]['status'], 'open: the failed service is retried on the next --resume');
    }

    public function testScopeDefaultsAndOptIns(): void
    {
        $active = $this->movableService(1);
        $suspended = $this->planService($this->gold, $this->de, VpnServiceStatus::SUSPENDED, 2);
        $expired = $this->planService($this->gold, $this->de, VpnServiceStatus::EXPIRED, 3);
        $deleted = $this->planService($this->gold, $this->de, VpnServiceStatus::DELETED, 4);
        $trial = $this->planService($this->gold, $this->de, n: 5, type: OrderType::TRIAL);
        $onTarget = $this->planService($this->gold, $this->nl, n: 6);
        $this->sellOn($this->gold, $this->nl);

        [, $output] = $this->command(['--json' => true]);
        $listed = array_column(json_decode($output, true)['services'], 'skip_reason', 'service_id');
        self::assertSame([$active->getId() => null, $suspended->getId() => 'suspended', $expired->getId() => 'expired', $trial->getId() => 'trial'], $listed, 'deleted never, a service already on the target not at all');
        self::assertArrayNotHasKey((int) $deleted->getId(), $listed);
        self::assertArrayNotHasKey((int) $onTarget->getId(), $listed);

        [, $output] = $this->command(['--json' => true, '--include-suspended' => true, '--include-expired' => true, '--include-trials' => true]);
        $listed = array_column(json_decode($output, true)['services'], 'action', 'service_id');
        self::assertSame(['move', 'move', 'move', 'move'], array_values($listed));

        [, $output] = $this->command(['--json' => true, '--limit' => '2', '--after-id' => (string) $active->getId()]);
        self::assertSame([$suspended->getId(), $expired->getId()], array_column(json_decode($output, true)['services'], 'service_id'));
        [, $output] = $this->command(['--json' => true, '--from-inbound' => (string) $this->nl->getId()]);
        self::assertSame([], json_decode($output, true)['services']);
    }

    public function testAServiceInAnotherUnfinishedRunOrMovedTooOftenIsSkipped(): void
    {
        $busy = $this->movableService(1);
        $flapping = $this->movableService(2);
        $db = $this->em->getConnection();
        $db->insert('inbound_move_run', ['plan_id' => $this->gold->getId(), 'open_plan_id' => null, 'kind' => 'primary', 'target_inbound_id' => $this->nl->getId(), 'scope' => '{}', 'status' => 'done', 'grace_hours' => 24, 'keep_old' => 0, 'created_at' => '2026-01-01 00:00:00', 'planned_count' => 0, 'done_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'remaining_count' => 0]);
        $old = (int) $db->lastInsertId();
        // Phase 2 still due for it (P6's detach): unfinished.
        $db->insert('service_inbound_move', ['run_id' => $old, 'service_id' => $busy->getId(), 'state' => 'switched', 'detach_after' => '2099-01-01 00:00:00', 'attempts' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        foreach (range(1, 3) as $n) {
            $db->insert('inbound_move_run', ['plan_id' => $this->gold->getId(), 'open_plan_id' => null, 'kind' => 'primary', 'target_inbound_id' => $this->nl->getId(), 'scope' => '{}', 'status' => 'done', 'grace_hours' => 24, 'keep_old' => 1, 'created_at' => '2026-01-01 00:00:00', 'planned_count' => 0, 'done_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'remaining_count' => 0]);
            $db->insert('service_inbound_move', ['run_id' => (int) $db->lastInsertId(), 'service_id' => $flapping->getId(), 'state' => 'switched', 'attempts' => 1, 'created_at' => (new \DateTimeImmutable('-'.$n.' hours'))->format('Y-m-d H:i:s'), 'updated_at' => (new \DateTimeImmutable('-'.$n.' hours'))->format('Y-m-d H:i:s')]);
        }
        $this->fake->requests = [];

        [$code, $output] = $this->command(['--apply' => true]);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString(sprintf('Service #%d (active, on DE-1): skipped: in run #%d', $busy->getId(), $old), $output);
        self::assertStringContainsString(sprintf('Service #%d (active, on DE-1): skipped: moved too often (3 moves within 24 hours)', $flapping->getId()), $output);
        self::assertSame([], $this->fake->requests);
    }

    public function testAProtocolMixIsSkippedAndAnInactiveTargetRefusesTheRun(): void
    {
        $service = $this->movableService(1);
        $wireguard = (new VpnInbound())->setPanel($this->managed($this->modernPanel))->setRemoteInboundId('3')->setTitle('WG')->setProtocol('wireguard');
        $this->em->persist($wireguard);
        $this->em->flush();
        $this->em->getConnection()->insert('vpn_service_inbound', ['service_id' => $service->getId(), 'inbound_id' => $wireguard->getId(), 'remote_inbound_id' => '3', 'status' => 'attached', 'attempts' => 1, 'updated_at' => '2026-01-01 00:00:00', 'reattach_count' => 0]);

        [, $output] = $this->command([]);
        self::assertStringContainsString('protocol mix', $output);

        // Two Shadowsocks inbounds with different methods (inbounds/options): a mix too, read with one GET.
        $this->em->getConnection()->executeStatement('DELETE FROM vpn_service_inbound');
        $ssA = $this->fake->addInbound(['protocol' => 'shadowsocks', 'ssMethod' => 'aes-256-gcm']);
        $ssB = $this->fake->addInbound(['protocol' => 'shadowsocks', 'ssMethod' => 'chacha20-ietf-poly1305']);
        $nl = $this->managed($this->nl)->setProtocol('shadowsocks');
        $ss = (new VpnInbound())->setPanel($this->managed($this->modernPanel))->setRemoteInboundId((string) $ssB)->setTitle('SS-B')->setProtocol('shadowsocks');
        $this->em->persist($ss);
        $nl->setRemoteInboundId((string) $ssA);
        $this->em->flush();
        $this->em->getConnection()->insert('vpn_service_inbound', ['service_id' => $service->getId(), 'inbound_id' => $ss->getId(), 'remote_inbound_id' => (string) $ssB, 'status' => 'attached', 'attempts' => 1, 'updated_at' => '2026-01-01 00:00:00', 'reattach_count' => 0]);
        $this->fake->requests = [];
        [, $output] = $this->command(['--json' => true]);
        self::assertSame('protocol_mix', json_decode($output, true)['services'][0]['skip_reason']);
        self::assertSame(['GET panel/api/inbounds/options'], $this->fake->requestLines(), 'the options only: a skipped service is not read');

        $this->managed($this->nl)->setIsActive(false);
        $this->em->flush();
        [$code, $output] = $this->command(['--apply' => true]);
        self::assertSame(2, $code);
        self::assertStringContainsString('is inactive: no run is created', $output);
        self::assertSame([], $this->runs());
    }

    public function testASaleDuringAnOpenRunGetsThePlansCurrentPrimaryAndIsNotPartOfTheRun(): void
    {
        $service = $this->movableService(1);
        $this->fake->clients['tg_p1_1']['inboundIds'] = [3];
        [$code] = $this->command(['--apply' => true]);
        self::assertSame(1, $code);
        self::assertSame('running', $this->runs()[0]['status']);

        $order = $this->createOrder($this->managed($this->owner), $this->managed($this->gold));
        $payment = $this->createPayment($order, null, PaymentStatus::SUBMITTED);
        $this->em->clear();
        $settled = self::getContainer()->get(PaymentSettlementService::class)->approveByAdmin($this->em->find(\App\Entity\Payment::class, $payment->getId()) ?? self::fail('payment'), 1, null, 'test');

        self::assertSame('confirmed', $settled->outcome->value);
        $sold = $this->em->getConnection()->fetchAssociative('SELECT id, inbound_id, inbound_changed_at FROM vpn_service WHERE order_id = ?', [$order->getId()]) ?: [];
        self::assertSame([(string) $this->nl->getId(), null], [(string) $sold['inbound_id'], $sold['inbound_changed_at']], 'the plan\'s current primary, as today');
        self::assertSame([(int) $service->getId()], array_map('intval', array_column($this->moves(), 'service_id')), 'not added to the frozen run');
        self::assertInstanceOf(VpnService::class, $this->em->find(VpnService::class, (int) $sold['id']));
    }
}
