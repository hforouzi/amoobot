<?php

declare(strict_types=1);

namespace App\Provisioning\Domain;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\Dto\VpnUsage;

/**
 * Drivers that read the usage of many clients of one panel in one request (usage sync), instead of one per client.
 */
interface BatchUsageDriverInterface
{
    /**
     * @param list<string> $remoteIds
     *
     * @return array<string, VpnUsage> by remote id; a client the panel does not report is missing from the result
     */
    public function getUsages(VpnPanel $panel, array $remoteIds): array;
}
