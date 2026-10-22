<?php

declare(strict_types=1);

namespace App\Tests\Provisioning;

use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Application\ServiceAdminOutcome;
use App\Provisioning\Application\ServiceAdministration;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Tests\Support\BotAdminTestTrait;
use App\Tests\Support\IntegrationTestCase;

/**
 * ServiceAdministration, extracted from the bot's ServiceManagementService: the bot's buttons and a direct call (as
 * the Mini App makes it) change a service in exactly the same way, and refusals change nothing.
 */
final class ServiceAdministrationTest extends IntegrationTestCase
{
    use BotAdminTestTrait;

    private const GB = 1073741824;

    public function testTheBotAndTheServiceChangeAServiceTheSameWay(): void
    {
        $user = $this->createUser('8711');
        $inbound = $this->createTestInbound();
        $viaBot = $this->createActiveService($user, $inbound);
        $direct = $this->createActiveService($user, $inbound);
        $administration = self::getContainer()->get(ServiceAdministration::class);

        $steps = [
            ['service_suspend:%d', static fn (VpnService $s): ServiceAdminOutcome => $administration->suspend($s)],
            ['service_activate:%d', static fn (VpnService $s): ServiceAdminOutcome => $administration->activate($s)],
            ['service_extend:%d:30', static fn (VpnService $s): ServiceAdminOutcome => $administration->extend($s, 30)],
            ['service_add_traffic:%d:10', static fn (VpnService $s): ServiceAdminOutcome => $administration->addTraffic($s, 10)],
            ['service_reset_usage:%d', static fn (VpnService $s): ServiceAdminOutcome => $administration->resetUsage($s)],
            ['service_delete_confirm:%d', static fn (VpnService $s): ServiceAdminOutcome => $administration->delete($s)],
        ];
        $this->em->getConnection()->executeStatement('UPDATE vpn_service SET traffic_used_bytes = ?, traffic_used_gb = 3 WHERE id IN (?, ?)', [3 * self::GB, $viaBot->getId(), $direct->getId()]);
        $this->em->clear();

        foreach ($steps as [$callback, $change]) {
            $this->pressButton(self::ENV_ADMIN, sprintf($callback, $viaBot->getId()));
            $service = $this->em->find(VpnService::class, $direct->getId());
            self::assertSame(ServiceAdminOutcome::DONE, $change($service), $callback);
            $this->em->clear();

            $a = $this->row('vpn_service', (int) $viaBot->getId());
            $b = $this->row('vpn_service', (int) $direct->getId());
            foreach (['status', 'traffic_limit_gb', 'traffic_limit_bytes', 'traffic_used_gb', 'traffic_used_bytes'] as $column) {
                self::assertSame($a[$column], $b[$column], $callback.': '.$column);
            }
            self::assertEqualsWithDelta(strtotime((string) $a['expires_at']), strtotime((string) $b['expires_at']), 5, $callback.': expires_at');
        }

        $final = $this->row('vpn_service', (int) $direct->getId());
        self::assertSame(VpnServiceStatus::DELETED, $final['status']);
        self::assertSame(60, (int) $final['traffic_limit_gb']);
        self::assertSame(0, (int) $final['traffic_used_bytes']);
    }

    public function testRefusalsChangeNothing(): void
    {
        $service = $this->createActiveService($this->createUser('8712'), $this->createTestInbound());
        $administration = self::getContainer()->get(ServiceAdministration::class);

        self::assertSame(ServiceAdminOutcome::ALREADY_ACTIVE, $administration->activate($service));
        self::assertSame(ServiceAdminOutcome::INVALID_AMOUNT, $administration->extend($service, 0));
        self::assertSame(ServiceAdminOutcome::INVALID_AMOUNT, $administration->extend($service, ServiceAdministration::MAX_DAYS + 1));
        self::assertSame(ServiceAdminOutcome::INVALID_AMOUNT, $administration->addTraffic($service, -5));
        self::assertSame(ServiceAdminOutcome::DONE, $administration->suspend($service));
        self::assertSame(ServiceAdminOutcome::ALREADY_SUSPENDED, $administration->suspend($service));
        self::assertSame(ServiceAdminOutcome::DONE, $administration->delete($service));
        self::assertSame(ServiceAdminOutcome::NOT_ALLOWED, $administration->suspend($service));
        self::assertSame(ServiceAdminOutcome::NOT_ALLOWED, $administration->activate($service));
        self::assertSame(ServiceAdminOutcome::ALREADY_DELETED, $administration->delete($service));

        $service->setTrafficLimitGb(intdiv(PHP_INT_MAX, self::GB) - 1);
        self::assertSame(ServiceAdminOutcome::LIMIT_OVERFLOW, $administration->addTraffic($service, 10));
    }

    public function testAFailingPanelChangesNothingHere(): void
    {
        $panel = (new VpnPanel())->setType('sanaei_3xui')->setTitle('Unreachable')->setBaseUrl('https://panel.invalid:2053/secret-path');
        $this->em->persist($panel);
        $service = $this->createActiveService($this->createUser('8713'));
        $service->setPanel($panel);
        $this->em->flush();

        $administration = self::getContainer()->get(ServiceAdministration::class);
        self::assertSame(ServiceAdminOutcome::PANEL_FAILED, $administration->suspend($service));
        self::assertSame(ServiceAdminOutcome::PANEL_FAILED, $administration->resetUsage($service));
        $this->em->clear();
        self::assertSame(VpnServiceStatus::ACTIVE, $this->row('vpn_service', (int) $service->getId())['status']);
    }
}
