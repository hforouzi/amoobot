<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

final readonly class HttpResult
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public bool $success,
        public ?string $message,
        public mixed $payload,
        public string $rawResponse,
        public int $statusCode,
        public array $headers,
        public string $requestId,
        public bool $enveloped = false,
    ) {
    }
}
