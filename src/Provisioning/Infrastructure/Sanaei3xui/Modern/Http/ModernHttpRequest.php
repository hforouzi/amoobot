<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

final readonly class ModernHttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $transportOptions
     */
    public function __construct(
        public string $method,
        public string $url,
        public string $path,
        public array $headers,
        public ?string $encodedBody,
        public string $requestId,
        public array $transportOptions = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toSymfonyOptions(): array
    {
        $options = $this->transportOptions;
        $options['headers'] = $this->headers;

        if (null !== $this->encodedBody) {
            $options['body'] = $this->encodedBody;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'path' => $this->path,
            'headers' => $this->redactedHeaders(),
            'encodedBody' => null === $this->encodedBody ? null : '[json-body]',
            'requestId' => $this->requestId,
            'transportOptions' => array_diff_key($this->transportOptions, ['headers' => true, 'body' => true]),
        ];
    }

    public function __toString(): string
    {
        return sprintf(
            '%s %s request_id=%s headers=%s',
            $this->method,
            $this->path,
            $this->requestId,
            json_encode($this->redactedHeaders(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @return array<string, string>
     */
    private function redactedHeaders(): array
    {
        $headers = $this->headers;
        foreach ($headers as $name => $value) {
            if (0 === strcasecmp($name, 'Authorization')) {
                $headers[$name] = '[redacted]';
            }
        }

        return $headers;
    }
}
