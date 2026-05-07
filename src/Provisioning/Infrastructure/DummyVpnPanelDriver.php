<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\CreatedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Provisioning\Domain\VpnPanelDriverInterface;

class DummyVpnPanelDriver implements VpnPanelDriverInterface
{
    public function supports(?VpnPanel $panel): bool
    {
        return null === $panel || 'dummy' === $panel->getType();
    }

    public function createService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): CreatedVpnService
    {
        $token = bin2hex(random_bytes(8));

        return new CreatedVpnService(
            remoteId: 'dummy_'.$token,
            username: $request->username,
            subscriptionUrl: 'https://example.com/sub/'.$token,
            configText: 'vless://dummy-config-for-user'
        );
    }

    public function suspendService(string $remoteId, ?VpnPanel $panel = null): void
    {
    }

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void
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
        return new VpnUsage(trafficUsedGb: 0, trafficLimitGb: null);
    }
}
