<?php

declare(strict_types=1);

namespace App\Tests\Shop\MultiInbound;

use App\Admin\UI\Crud\VpnInboundCrudController;
use App\Admin\UI\Crud\VpnPanelCrudController;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Application\ServiceAdministration;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Shop\Application\Catalog\PlanInboundUsage;
use App\Tests\Shop\PlanInboundChange\PlanInboundChangeFixtures;
use App\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * R2 of docs/design/plan-inbound-add-remove.md: a deleted service never stops an inbound from being deleted. Its rows go
 * with it, and those that services deleted in the past left behind (no migration removes them) go with the inbound.
 * A row of a live service still refuses, whatever its status.
 */
final class DeletedServiceRowsTest extends IntegrationTestCase
{
    use PlanInboundChangeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInboundChange();
    }

    public function testDeletingAServiceLeavesNothingThatHoldsTheInbound(): void
    {
        $service = $this->serviceWithRow(1, VpnServiceInbound::STATUS_ATTACHED);

        self::assertSame(1, $this->rowsOf($this->nl));
        $this->em->clear();
        self::getContainer()->get(ServiceAdministration::class)->delete($this->em->find(VpnService::class, $service->getId()) ?? self::fail('service'));

        self::assertSame(0, $this->rowsOf($this->nl));
        self::assertNull(self::getContainer()->get(PlanInboundUsage::class)->deletionProblem([(int) $this->nl->getId()], 'flash.inbound.used_by_plans'));
    }

    public function testARowLeftBehindByAServiceDeletedInThePastIsNoObstacle(): void
    {
        $this->deletedLongAgo(1);
        $id = (int) $this->nl->getId();

        self::assertNull(self::getContainer()->get(PlanInboundUsage::class)->deletionProblem([$id], 'flash.inbound.used_by_plans'));
        self::assertSame(1, $this->rowsOf($this->nl), 'asking changes nothing');

        $this->deleteInbound();

        self::assertNull($this->em->find(VpnInbound::class, $id));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service_inbound WHERE inbound_id = ?', [$id]));
    }

    public function testALeftOverRowOnlyGoesWithItsInbound(): void
    {
        $other = $this->panelInbound(3, 'FR-3');
        $this->em->flush();
        $kept = $this->deletedLongAgo(1);
        $this->em->getConnection()->insert('vpn_service_inbound', $this->rowOf($kept, $other, VpnServiceInbound::STATUS_DETACHED));

        $this->deleteInbound();

        self::assertSame(1, $this->rowsOf($other));
    }

    /** @return iterable<string, array{string}> */
    public static function liveStatuses(): iterable
    {
        yield 'attached' => [VpnServiceInbound::STATUS_ATTACHED];
        yield 'pending' => [VpnServiceInbound::STATUS_PENDING];
        yield 'failed' => [VpnServiceInbound::STATUS_FAILED];
        // A remove that failed holds its row (design 5.2): the client may still be on the inbound.
        yield 'detached' => [VpnServiceInbound::STATUS_DETACHED];
    }

    #[DataProvider('liveStatuses')]
    public function testARowOfALiveServiceStillRefuses(string $status): void
    {
        $this->serviceWithRow(1, $status);

        $problem = self::getContainer()->get(PlanInboundUsage::class)->deletionProblem([(int) $this->nl->getId()], 'flash.inbound.used_by_plans');

        self::assertSame(['key' => 'flash.inbound.held_by_services', 'parameters' => ['%count%' => 1]], $problem);
    }

    public function testOnlyLiveServicesAreCounted(): void
    {
        $this->deletedLongAgo(1);
        $this->serviceWithRow(2, VpnServiceInbound::STATUS_ATTACHED);
        $id = (int) $this->nl->getId();

        self::assertSame(['key' => 'flash.inbound.held_by_services', 'parameters' => ['%count%' => 1]], self::getContainer()->get(PlanInboundUsage::class)->deletionProblem([$id], 'flash.inbound.used_by_plans'));
    }

    public function testAPanelGoesWithTheRowsLeftOnItsInbounds(): void
    {
        $this->deletedLongAgo(1);
        // A service on another panel's inbound would stop nothing here: this one's inbounds are all that count.
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET inbound_id = NULL, panel_id = NULL');
        $panelId = (int) $this->modernPanel->getId();
        $this->em->getConnection()->executeStatement('UPDATE plan SET inbound_id = NULL');
        $this->em->clear();
        $panel = $this->em->find(VpnPanel::class, $panelId) ?? self::fail('panel');

        self::getContainer()->get(VpnPanelCrudController::class)->deleteEntity($this->em, $panel);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_panel WHERE id = ?', [$panelId]));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service_inbound'));
    }

    private function deleteInbound(): void
    {
        $id = (int) $this->nl->getId();
        $this->em->clear();
        self::getContainer()->get(VpnInboundCrudController::class)->deleteEntity($this->em, $this->em->find(VpnInbound::class, $id) ?? self::fail('inbound'));
        $this->em->clear();
    }

    /** A service with a row for NL-2, deleted before P11c: the row stayed, "detached". */
    private function deletedLongAgo(int $n): VpnService
    {
        $service = $this->serviceWithRow($n, VpnServiceInbound::STATUS_DETACHED);
        $this->em->getConnection()->update('vpn_service', ['status' => VpnServiceStatus::DELETED], ['id' => $service->getId()]);

        return $service;
    }

    private function serviceWithRow(int $n, string $status): VpnService
    {
        $service = $this->planService($this->gold, $this->de, n: $n);
        $this->em->getConnection()->insert('vpn_service_inbound', $this->rowOf($service, $this->nl, $status));

        return $service;
    }

    /** @return array<string, mixed> */
    private function rowOf(VpnService $service, VpnInbound $inbound, string $status): array
    {
        return [
            'service_id' => $service->getId(),
            'inbound_id' => $inbound->getId(),
            'remote_inbound_id' => $inbound->getRemoteInboundId(),
            'status' => $status,
            'attempts' => 0,
            'updated_at' => '2026-01-01 00:00:00',
            'reattach_count' => 0,
        ];
    }

    private function rowsOf(VpnInbound $inbound): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM vpn_service_inbound WHERE inbound_id = ?', [$inbound->getId()]);
    }
}
