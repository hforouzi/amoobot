<?php

declare(strict_types=1);

namespace App\Tests\Shop\PlanInboundChange;

use App\Entity\Plan;
use App\Entity\PlanAdditionalInbound;
use App\Entity\Reseller;
use App\Entity\TrialPlan;
use App\Entity\User;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\Catalog\PlanInboundDrift;
use App\Shop\Application\MultiInboundSettings;
use App\Shop\Domain\OrderStatus;
use App\Shop\Domain\OrderType;
use App\Tests\Support\Fake3xui\Fake3xuiPanel;
use App\Tests\Support\IntegrationTestCase;
use App\Tests\Support\QueryCounter;

/**
 * T1 of docs/design/plan-inbound-change.md 8.2 (P1): PlanInboundDrift counts the existing services of a plan that are
 * not on the inbound the plan now sells, by inbound and by status, deleted ones left out, trials against their trial
 * plan's inbound, reseller-sold and claimed services counted without naming anyone, services on another panel apart,
 * the additional-inbound rows with the switch on. A fixed number of queries whatever the number of services or plans,
 * and nothing written.
 */
final class PlanInboundDriftTest extends IntegrationTestCase
{
    private VpnPanel $panel;
    private VpnInbound $a;
    private VpnInbound $b;
    private VpnInbound $c;
    private VpnInbound $d;
    private VpnInbound $elsewhere;
    private Plan $plan;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->panel = $this->panel('modern');
        $this->a = $this->inbound($this->panel, 1, 'DE-1');
        $this->b = $this->inbound($this->panel, 2, 'NL-2');
        $this->c = $this->inbound($this->panel, 3, 'FI-3');
        $this->d = $this->inbound($this->panel, 4, 'SE-4');
        $this->elsewhere = $this->inbound($this->panel('other'), 1, 'US-9');
        $this->em->flush();
        $this->plan = $this->createPlan(100000, $this->a)->setTitle('Gold');
        $this->user = $this->createUser('8930001');
    }

    public function testNothingToCountWhenEveryServiceIsOnThePlansInbound(): void
    {
        $this->service($this->plan, $this->a);
        $this->service($this->plan, $this->a, VpnServiceStatus::SUSPENDED);

        $drift = $this->drift()->ofPlan($this->plan);

        self::assertSame(0, $drift->total);
        self::assertSame([], $drift->byInbound);
        self::assertSame(0, $this->drift()->totalOf((int) $this->plan->getId()));
        self::assertFalse($this->drift()->anyPlan());
    }

    public function testCountsByInboundAndStatusWithDeletedLeftOut(): void
    {
        $this->service($this->plan, $this->a);
        foreach ([VpnServiceStatus::ACTIVE, VpnServiceStatus::ACTIVE, VpnServiceStatus::SUSPENDED, VpnServiceStatus::EXPIRED, VpnServiceStatus::DELETED] as $status) {
            $this->service($this->plan, $this->b, $status);
        }
        $this->service($this->plan, $this->c, VpnServiceStatus::EXPIRED);
        $this->service($this->plan, $this->c, VpnServiceStatus::DELETED);
        $this->changeInbound($this->plan, $this->a, $this->d);

        $drift = $this->drift()->ofPlan($this->plan);

        self::assertSame(6, $drift->total, 'A (now old) 1, B 4, C 1; the two deleted ones are not counted');
        self::assertSame([
            ['id' => (int) $this->b->getId(), 'title' => 'NL-2', 'count' => 4],
            ['id' => (int) $this->a->getId(), 'title' => 'DE-1', 'count' => 1],
            ['id' => (int) $this->c->getId(), 'title' => 'FI-3', 'count' => 1],
        ], $drift->byInbound, 'most services first');
        self::assertSame([VpnServiceStatus::ACTIVE => 3, VpnServiceStatus::EXPIRED => 2, VpnServiceStatus::SUSPENDED => 1], $drift->byStatus);
        self::assertArrayNotHasKey(VpnServiceStatus::DELETED, $drift->byStatus);
        self::assertSame(0, $drift->otherPanel);
        self::assertNull($drift->additionalMissing, 'switch off: the additional rows are not counted');
        self::assertSame(6, $this->drift()->totalOf((int) $this->plan->getId()), 'the list total is the same number');
        self::assertSame('4 on "NL-2", 1 on "DE-1", 1 on "FI-3"', $this->drift()->breakdown($drift, 'en'));
        self::assertSame('4 روی «NL-2»، 1 روی «DE-1»، 1 روی «FI-3»', $this->drift()->breakdown($drift, 'fa'));
    }

    public function testTheBreakdownShowsAtMostThreeInboundsThenAnEllipsis(): void
    {
        foreach ([$this->a, $this->b, $this->b, $this->c, $this->c, $this->c, $this->elsewhere] as $inbound) {
            $this->service($this->plan, $inbound);
        }
        $deleted = $this->inbound($this->panel, 9, 'gone');
        $this->em->flush();
        $orphan = $this->service($this->plan, $deleted);
        $this->em->getConnection()->delete('vpn_inbound', ['id' => $deleted->getId()]);
        $this->em->refresh($orphan);
        self::assertNull($orphan->getInbound(), 'a deleted local inbound leaves the service with none (SET NULL)');
        $this->changeInbound($this->plan, $this->a, $this->d);

        $drift = $this->drift()->ofPlan($this->plan);

        self::assertSame(8, $drift->total);
        self::assertSame('3 on "FI-3", 2 on "NL-2", 1 on "DE-1", …', $this->drift()->breakdown($drift, 'en'));
        self::assertContains(['id' => null, 'title' => null, 'count' => 1], $drift->byInbound, 'the service whose inbound was deleted');
        self::assertSame('(deleted)', $this->drift()->inboundTitle(null, 'en'));
        self::assertSame(1, $drift->otherPanel, 'the service on the other panel is counted apart: no attach can reach it');
    }

    public function testResellerSoldAndClaimedServicesAreCountedWithoutNamingAnyone(): void
    {
        $resellerUser = $this->createUser('8930002')->setName('Reseller Rostam');
        $reseller = (new Reseller($resellerUser))->setDisplayName('Rostam Shop');
        $this->em->persist($reseller);
        $sold = $this->service($this->plan, $this->b)->setSoldByReseller($reseller)->setUser($resellerUser);
        $claimer = $this->createUser('8930003')->setName('Claimer Sohrab');
        $claimed = $this->service($this->plan, $this->b)->setSoldByReseller($reseller)->setUser($claimer)
            ->setClientUuid('aaaaaaaa-1111-2222-3333-444444444444')->setConfigLinks(['vless://aaaaaaaa-1111-2222-3333-444444444444@x.example:443#c']);
        $this->em->flush();

        $drift = $this->drift()->ofPlan($this->plan);
        $notices = $this->drift()->ofServices([(int) $sold->getId(), (int) $claimed->getId()]);

        self::assertSame(2, $drift->total, 'both counted like any other service');
        $everything = json_encode([$drift, $notices, $this->drift()->breakdown($drift, 'en'), $this->drift()->serviceText('plan_inbound_drift.service', $notices[(int) $claimed->getId()], 'en')], JSON_UNESCAPED_UNICODE);
        foreach (['Rostam', 'Sohrab', '8930002', '8930003', 'aaaaaaaa', 'vless://', 'x.example'] as $secret) {
            self::assertStringNotContainsString($secret, (string) $everything, 'D5: no owner, reseller or link');
        }
        self::assertSame(['current' => 'NL-2', 'planInbound' => 'DE-1'], $notices[(int) $claimed->getId()]);
    }

    public function testTrialServicesAreComparedWithTheTrialPlansInbound(): void
    {
        // The trial plan now gives B; its hidden backing plan still names A until the next claim copies B onto it.
        $backing = $this->createPlan(0, $this->a)->setTitle('[Trial] Test')->setIsActive(false);
        $trial = (new TrialPlan())->setTitle('Test')->setDurationDays(1)->setInbound($this->b)->setBackingPlan($backing);
        $this->em->persist($trial);
        $this->em->flush();
        $onA = $this->service($backing, $this->a, VpnServiceStatus::ACTIVE, OrderType::TRIAL);
        $this->service($backing, $this->b, VpnServiceStatus::ACTIVE, OrderType::TRIAL);
        // A normal plan's services are never mixed with the trial's.
        $this->service($this->plan, $this->b);

        self::assertSame($this->b, $this->drift()->effectivePrimary($backing));
        $drift = $this->drift()->ofPlan($backing);
        self::assertSame(1, $drift->total, 'the trial service still on A');
        self::assertSame(1, $this->drift()->totalOf((int) $backing->getId()));
        self::assertSame(1, $this->drift()->totalOf((int) $this->plan->getId()), 'the normal plan: its own service on B');
        self::assertSame(['current' => 'DE-1', 'planInbound' => 'NL-2'], $this->drift()->ofServices([(int) $onA->getId()])[(int) $onA->getId()]);
        self::assertSame(1, $this->drift()->ofPlan($backing, $this->b)->total, 'the trial forms pass the trial plan\'s inbound');
    }

    public function testTheAdditionalInboundRowsAreCountedWithTheSwitchOn(): void
    {
        $this->em->getConnection()->insert('setting', ['key_name' => MultiInboundSettings::ENABLED, 'value' => 'true', 'type' => 'boolean', 'created_at' => '2026-01-01 00:00:00']);
        $this->em->persist(new PlanAdditionalInbound($this->plan, $this->b, 1, new \DateTimeImmutable()));
        $this->em->flush();
        $complete = $this->service($this->plan, $this->a);
        $this->inboundRow($complete, $this->b, VpnServiceInbound::STATUS_ATTACHED);
        $failed = $this->service($this->plan, $this->a);
        $this->inboundRow($failed, $this->b, VpnServiceInbound::STATUS_FAILED);
        $this->service($this->plan, $this->a); // no row for B: sold before B was added (M3)
        $detached = $this->service($this->plan, $this->a);
        $this->inboundRow($detached, $this->b, VpnServiceInbound::STATUS_DETACHED);
        $stale = $this->service($this->plan, $this->a);
        $this->inboundRow($stale, $this->b, VpnServiceInbound::STATUS_ATTACHED);
        $this->inboundRow($stale, $this->c, VpnServiceInbound::STATUS_PENDING); // C is no longer one of the plan's (M4)
        $gone = $this->service($this->plan, $this->a, VpnServiceStatus::DELETED);
        $this->inboundRow($gone, $this->c, VpnServiceInbound::STATUS_ATTACHED);
        $this->em->clear();

        $drift = $this->drift()->ofPlan($this->em->find(Plan::class, $this->plan->getId()) ?? self::fail());

        self::assertSame(0, $drift->total, 'all on the primary');
        self::assertSame(2, $drift->additionalMissing, 'no row, or only a detached one');
        self::assertSame(1, $drift->additionalExtra, 'still holds C; the deleted service is not counted');
    }

    public function testAFixedNumberOfQueriesAndNoWrite(): void
    {
        $plans = [$this->plan];
        for ($i = 0; $i < 5; ++$i) {
            $plans[] = $this->createPlan(100000, $this->a)->setTitle('P'.$i);
        }
        $this->em->flush();
        $counter = self::getContainer()->get(QueryCounter::class);
        $perPlan = [];
        $listQueries = [];
        $planIds = array_map(static fn (Plan $p): int => (int) $p->getId(), $plans);
        [$userId, $bId, $cId] = [(int) $this->user->getId(), (int) $this->b->getId(), (int) $this->c->getId()];
        foreach ([1, 12] as $services) {
            // Managed again after the clear() of the previous round.
            $this->user = $this->em->find(User::class, $userId) ?? self::fail();
            [$b, $c] = [$this->em->find(VpnInbound::class, $bId) ?? self::fail(), $this->em->find(VpnInbound::class, $cId) ?? self::fail()];
            $plans = array_map(fn (int $id): Plan => $this->em->find(Plan::class, $id) ?? self::fail(), $planIds);
            foreach ($plans as $plan) {
                for ($n = 0; $n < $services; ++$n) {
                    $this->service($plan, 0 === $n % 2 ? $b : $c);
                }
            }
            $this->em->clear();
            $fresh = $this->em->find(Plan::class, $this->plan->getId()) ?? self::fail();
            $tables = $this->tableChecksums();
            $this->drift()->reset();

            $counter->start();
            $this->drift()->ofPlan($fresh);
            $perPlan[] = $counter->stop();

            $counter->start();
            foreach ($plans as $plan) {
                $this->drift()->totalOf((int) $plan->getId());
            }
            $this->drift()->anyPlan();
            $listQueries[] = $counter->stop();
            self::assertSame($tables, $this->tableChecksums(), 'read only');
        }

        self::assertSame($perPlan[0], $perPlan[1], 'one plan: the same queries for 1 or 12 services each');
        self::assertLessThanOrEqual(3, $perPlan[0], 'the trial-plan lookup, the grouped count (and at most the switch setting)');
        self::assertSame([1, 1], $listQueries, 'a list page: one grouped query for every plan');

        $ids = array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT id FROM vpn_service'));
        $counter->start();
        $this->drift()->ofServices($ids);
        self::assertSame(1, $counter->stop(), 'a batch of services: one query');
    }

    private function drift(): PlanInboundDrift
    {
        return self::getContainer()->get(PlanInboundDrift::class);
    }

    /** As the forms do: the plan's primary changes from $from to $to (nothing else). */
    private function changeInbound(Plan $plan, VpnInbound $from, VpnInbound $to): void
    {
        self::assertSame($from, $plan->getInbound());
        $plan->setInbound($to);
        $this->em->flush();
    }

    private function service(Plan $plan, VpnInbound $inbound, string $status = VpnServiceStatus::ACTIVE, string $type = OrderType::NEW_SERVICE): VpnService
    {
        $order = $this->createOrder($this->user, $plan, $type)->setStatus(OrderStatus::COMPLETED);
        $service = $this->createActiveService($this->user, $inbound)->setOrder($order)->setStatus($status);
        $this->em->flush();

        return $service;
    }

    private function inboundRow(VpnService $service, VpnInbound $inbound, string $status): void
    {
        $this->em->getConnection()->insert('vpn_service_inbound', [
            'service_id' => $service->getId(), 'inbound_id' => $inbound->getId(), 'remote_inbound_id' => (string) $inbound->getRemoteInboundId(),
            'status' => $status, 'attempts' => 0, 'reattach_count' => 0, 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function tableChecksums(): array
    {
        $sums = [];
        foreach (['plan', 'order', 'vpn_service', 'vpn_service_inbound', 'plan_additional_inbound', 'trial_plan'] as $table) {
            $sums[$table] = (string) $this->em->getConnection()->fetchAssociative('CHECKSUM TABLE `'.$table.'`')['Checksum'];
        }

        return $sums;
    }

    private function panel(string $title): VpnPanel
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle($title)->setBaseUrl(Fake3xuiPanel::ORIGIN.'/secret/')->setPlainApiToken(Fake3xuiPanel::TOKEN)
            ->setIntegrationMode(SanaeiIntegrationMode::MODERN);
        $this->em->persist($panel);

        return $panel;
    }

    private function inbound(VpnPanel $panel, int $remote, string $title): VpnInbound
    {
        $inbound = (new VpnInbound())->setPanel($panel)->setRemoteInboundId((string) $remote)->setTitle($title)->setProtocol('vless')->setPort(10000 + $remote);
        $this->em->persist($inbound);

        return $inbound;
    }
}
