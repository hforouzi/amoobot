<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

final readonly class JsonResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public mixed $decoded,
        public string $rawResponse,
        public int $statusCode,
        public array $headers,
        public string $requestId,
    ) {
    }
}
