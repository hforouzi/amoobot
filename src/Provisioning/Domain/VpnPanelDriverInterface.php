<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\CreatedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\Dto\VpnUsage;

interface VpnPanelDriverInterface
{
    public function supports(?VpnPanel $panel): bool;

    public function createService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): CreatedVpnService;

    public function suspendService(string $remoteId, ?VpnPanel $panel = null): void;

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void;

    public function deleteService(string $remoteId, ?VpnPanel $panel = null): void;

    public function resetUsage(string $remoteId, ?VpnPanel $panel = null): void;

    public function getUsage(string $remoteId, ?VpnPanel $panel = null): VpnUsage;
}
