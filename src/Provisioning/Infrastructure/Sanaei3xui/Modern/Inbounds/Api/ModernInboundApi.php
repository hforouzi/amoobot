<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Inbounds\Api;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\Exception\InvalidResponseException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernHttpClientInterface;

final class ModernInboundApi
{
    public const LIST_ENDPOINT = '/panel/api/inbounds/list';

    public function __construct(
        private readonly ModernHttpClientInterface $httpClient,
    ) {
    }

    public function list(VpnPanel $panel): ModernInboundApiResponse
    {
        $result = $this->httpClient->request($panel, 'GET', self::LIST_ENDPOINT);

        if (!$result->enveloped) {
            throw new InvalidResponseException('Modern inbound list response envelope is missing.', $result->statusCode, $result->requestId);
        }

        if (!$result->success) {
            throw new InvalidResponseException($this->safeMessage($result->message ?: 'Modern inbound list returned success=false.'), $result->statusCode, $result->requestId);
        }

        if (!is_array($result->payload)) {
            throw new InvalidResponseException('Modern inbound list payload must be an array.', $result->statusCode, $result->requestId);
        }

        return new ModernInboundApiResponse($result->payload, $result->requestId);
    }

    private function safeMessage(string $message): string
    {
        $message = preg_replace('/Bearer\s+[^\s]+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/(api[_-]?token|token|password)=([^\s&]+)/i', '$1=[redacted]', $message) ?? $message;
        $message = preg_replace('/\b(api\s+token|token|password)\s+[^\s]+/i', '$1 [redacted]', $message) ?? $message;

        return $message;
    }
}
