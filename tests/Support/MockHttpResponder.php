<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Test-only response factory for framework.http_client (see config/packages/test/http_client.yaml).
 *
 * Every outgoing HTTP call in the test environment ends up here, so tests never reach Telegram,
 * payment gateways or VPN panels. Tests register handlers by URL substring; unmatched calls get a
 * generic Telegram-style {"ok": true} response. All requests are recorded for assertions.
 */
final class MockHttpResponder
{
    /** @var list<array{needle: string, handler: callable}> */
    private array $handlers = [];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /**
     * @param callable(string $method, string $url, array<string, mixed> $options): ResponseInterface|array<mixed> $handler
     */
    public function on(string $urlNeedle, callable $handler): void
    {
        array_unshift($this->handlers, ['needle' => $urlNeedle, 'handler' => $handler]);
    }

    /**
     * @param array<mixed> $json
     */
    public function onJson(string $urlNeedle, array $json, int $status = 200): void
    {
        $this->on($urlNeedle, static fn (): ResponseInterface => new MockResponse(
            (string) json_encode($json),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]
        ));
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        foreach ($this->handlers as $entry) {
            if (str_contains($url, $entry['needle'])) {
                $response = ($entry['handler'])($method, $url, $options);

                return $response instanceof ResponseInterface
                    ? $response
                    : new MockResponse((string) json_encode($response), ['response_headers' => ['content-type' => 'application/json']]);
            }
        }

        return new MockResponse('{"ok":true,"result":{"message_id":1}}', ['response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * @return list<array{method: string, url: string, options: array<string, mixed>}>
     */
    public function requests(?string $urlNeedle = null): array
    {
        if (null === $urlNeedle) {
            return $this->requests;
        }

        return array_values(array_filter($this->requests, static fn (array $r): bool => str_contains($r['url'], $urlNeedle)));
    }

    /**
     * Texts of all Telegram sendMessage calls, in order.
     *
     * @return list<string>
     */
    public function telegramTexts(): array
    {
        $texts = [];
        foreach ($this->requests('/sendMessage') as $request) {
            $body = $request['options']['body'] ?? $request['options']['json'] ?? null;
            if (is_string($body)) {
                $decoded = json_decode($body, true);
                if (!is_array($decoded)) {
                    parse_str($body, $decoded);
                }
                $body = $decoded;
            }
            if (is_array($body) && isset($body['text'])) {
                $texts[] = (string) $body['text'];
            }
        }

        return $texts;
    }

    public function reset(): void
    {
        $this->handlers = [];
        $this->requests = [];
    }
}
