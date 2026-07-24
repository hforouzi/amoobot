<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\CreatedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Domain\UnsupportedPanelOperationException;
use App\Provisioning\Domain\VpnPanelDriverInterface;

class Sanaei3xuiModernDriver implements VpnPanelDriverInterface
{
    public function supports(?VpnPanel $panel): bool
    {
        return $panel instanceof VpnPanel
            && 'sanaei_3xui' === $panel->getType()
            && SanaeiIntegrationMode::MODERN === $panel->getSanaeiIntegrationMode();
    }

    public function integrationMode(): SanaeiIntegrationMode
    {
        return SanaeiIntegrationMode::MODERN;
    }

    public function integrationKey(): string
    {
        return 'sanaei_3xui_modern';
    }

    public function createService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): CreatedVpnService
    {
        throw $this->unsupported('Provisioning');
    }

    public function suspendService(string $remoteId, ?VpnPanel $panel = null): void
    {
        throw $this->unsupported('Suspending services');
    }

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void
    {
        throw $this->unsupported('Renewing services');
    }

    public function deleteService(string $remoteId, ?VpnPanel $panel = null): void
    {
        throw $this->unsupported('Deleting services');
    }

    public function resetUsage(string $remoteId, ?VpnPanel $panel = null): void
    {
        throw $this->unsupported('Resetting usage');
    }

    public function getUsage(string $remoteId, ?VpnPanel $panel = null): VpnUsage
    {
        throw $this->unsupported('Traffic retrieval');
    }

    private function unsupported(string $operation): UnsupportedPanelOperationException
    {
        return new UnsupportedPanelOperationException(sprintf(
            '%s is not implemented for the Sanaei Modern integration yet.',
            $operation
        ));
    }
}
