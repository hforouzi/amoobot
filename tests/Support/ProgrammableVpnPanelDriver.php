<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\CreatedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Provisioning\Domain\VpnPanelDriverInterface;

/**
 * Test VPN panel driver for panels of type "test" (registered in config/services_test.yaml).
 *
 * - failNext(n): the next n create/renew calls throw, simulating a panel outage
 * - TEST_PANEL_DELAY_MS env var: sleeps inside createService, used to overlap concurrent settlements
 * - $usage / $usageFails: what getUsage() reports (the panel's current state of a client)
 */
final class ProgrammableVpnPanelDriver implements VpnPanelDriverInterface
{
    public const TYPE = 'test';

    private int $failuresLeft = 0;
    public int $createCalls = 0;
    public int $renewCalls = 0;
    public ?VpnUsage $usage = null;
    public bool $usageFails = false;

    public function failNext(int $times = 1): void
    {
        $this->failuresLeft = $times;
    }

    public function reset(): void
    {
        $this->failuresLeft = 0;
        $this->createCalls = 0;
        $this->renewCalls = 0;
        $this->usage = null;
        $this->usageFails = false;
    }

    public function supports(?VpnPanel $panel): bool
    {
        return $panel instanceof VpnPanel && self::TYPE === $panel->getType();
    }

    public function createService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): CreatedVpnService
    {
        ++$this->createCalls;
        $delay = (int) ($_SERVER['TEST_PANEL_DELAY_MS'] ?? getenv('TEST_PANEL_DELAY_MS') ?: 0);
        if ($delay > 0) {
            usleep($delay * 1000);
        }
        $this->maybeFail('createService');

        $token = bin2hex(random_bytes(6));

        return new CreatedVpnService(
            remoteId: 'test_'.$token,
            username: $request->username,
            subscriptionUrl: 'https://panel.test/sub',
            configText: 'vless://test',
            clientUuid: $token,
            clientEmail: $request->username,
            subId: $token,
            ipLimit: $request->ipLimit,
            configLinks: ['vless://test'],
        );
    }

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void
    {
        ++$this->renewCalls;
        $this->maybeFail('renewService');
    }

    public function suspendService(string $remoteId, ?VpnPanel $panel = null): void
    {
    }

    public function deleteService(string $remoteId, ?VpnPanel $panel = null): void
    {
    }

    public function resetUsage(string $remoteId, ?VpnPanel $panel = null): void
    {
    }

    public function getUsage(string $remoteId, ?VpnPanel $panel = null): VpnUsage
    {
        if ($this->usageFails) {
            throw new \RuntimeException('Simulated panel outage during getUsage');
        }

        return $this->usage ?? new VpnUsage(trafficUsedGb: 0, trafficLimitGb: null, usedBytes: 0, totalBytes: null);
    }

    private function maybeFail(string $operation): void
    {
        if ($this->failuresLeft > 0) {
            --$this->failuresLeft;
            throw new \RuntimeException(sprintf('Simulated panel outage during %s (token=supersecret123)', $operation));
        }
    }
}
