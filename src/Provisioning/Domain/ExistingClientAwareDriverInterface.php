<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\AdoptedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;

/**
 * Panel drivers that can look up the client a create request would produce (by its client UUID or
 * email/remark) before creating it, so a retried provisioning adopts it instead of duplicating it.
 */
interface ExistingClientAwareDriverInterface
{
    /**
     * @return AdoptedVpnService|null null when the panel has no such client
     *
     * @throws \RuntimeException when the panel cannot be read (the caller must not create then)
     */
    public function findExistingService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): ?AdoptedVpnService;
}
