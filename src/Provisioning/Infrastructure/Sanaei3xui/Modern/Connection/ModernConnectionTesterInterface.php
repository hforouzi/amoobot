<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Connection;

use App\Entity\VpnPanel;

interface ModernConnectionTesterInterface
{
    public function test(VpnPanel $panel): ModernConnectionTestResult;
}
