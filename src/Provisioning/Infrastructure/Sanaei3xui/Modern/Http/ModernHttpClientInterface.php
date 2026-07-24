<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

use App\Entity\VpnPanel;

interface ModernHttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function request(VpnPanel $panel, string $method, string $path, mixed $body = null, array $headers = []): HttpResult;
}
