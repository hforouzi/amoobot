<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Auth;

use Symfony\Component\Serializer\Attribute\Ignore;

final readonly class ModernPanelConfiguration
{
    /**
     * @param array<string, mixed> $proxyConfiguration
     */
    public function __construct(
        public string $baseUrl,
        #[Ignore]
        public string $apiToken,
        public int $timeout,
        public bool $tlsVerification,
        public array $proxyConfiguration,
        public int $retryCount,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'apiToken' => '[redacted]',
            'timeout' => $this->timeout,
            'tlsVerification' => $this->tlsVerification,
            'proxyConfiguration' => $this->proxyConfiguration,
            'retryCount' => $this->retryCount,
        ];
    }
}
