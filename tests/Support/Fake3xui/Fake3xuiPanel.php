<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake3xui;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An in-memory 3x-ui panel behind Symfony's MockHttpClient: the documented REST API with its {success, msg, obj}
 * envelope, bearer-token auth, the web base path, and the legacy cookie login for old panels. Stateful (clients,
 * inbounds, traffic), and scriptable for faults (network errors, HTML pages, success:false, lost responses).
 *
 * No real panel is ever contacted: requests to any other origin answer like a refused connection.
 */
final class Fake3xuiPanel
{
    public const ORIGIN = 'https://panel.example.test:2053';
    public const TOKEN = 'fake-token-0123456789abcdefFAKE';
    public const USERNAME = 'admin';
    public const PASSWORD = 'fake-password-1';

    /** modern = clients API + bearer; legacy = old panel (cookie login only, no clients API). */
    public string $mode = 'modern';
    public string $basePath = '/secret';
    public string $origin = self::ORIGIN;
    public string $token = self::TOKEN;
    public bool $tokenRevoked = false;

    /** @var array<int, array<string, mixed>> inbound id => inbound */
    public array $inbounds = [];
    /** @var array<string, array<string, mixed>> email => client row (with inboundIds) */
    public array $clients = [];
    /** @var array<string, array{up: int, down: int, lastOnline: int}> email => traffic */
    public array $traffic = [];

    /** @var list<array{method: string, path: string, query: array<string, string>, headers: array<string, list<string>>, body: mixed}> */
    public array $requests = [];

    /** @var list<array{method: ?string, path: ?string, response: MockResponse|\Closure, times: int, afterHandling: bool}> */
    private array $scripts = [];

    private ?string $session = null;
    private int $nextInboundId = 1;

    public function __construct()
    {
        $this->addInbound(['remark' => 'vless-reality', 'protocol' => 'vless', 'port' => 443, 'tlsFlowCapable' => true]);
        $this->addInbound(['remark' => 'vmess-ws', 'protocol' => 'vmess', 'port' => 8080]);
        $this->addInbound(['remark' => 'old-trojan', 'protocol' => 'trojan', 'port' => 8443, 'enable' => false]);
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient($this, $this->origin);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function addInbound(array $fields): int
    {
        $id = $this->nextInboundId++;
        $this->inbounds[$id] = array_merge([
            'id' => $id, 'remark' => 'inbound-'.$id, 'tag' => 'inbound-'.$id, 'protocol' => 'vless', 'port' => 10000 + $id,
            'enable' => true, 'tlsFlowCapable' => false, 'ssMethod' => '', 'nodeId' => 0,
        ], $fields);

        return $id;
    }

    /**
     * Answers the next matching request(s) with $response instead of the fake's logic. With $afterHandling the fake
     * still applies the request (state changes) and only the answer is replaced: a "lost response".
     *
     * @param MockResponse|\Closure(string, string, array<string, mixed>): MockResponse $response
     */
    public function script(?string $method, ?string $path, MockResponse|\Closure $response, int $times = 1, bool $afterHandling = false): self
    {
        $this->scripts[] = ['method' => $method, 'path' => $path, 'response' => $response, 'times' => $times, 'afterHandling' => $afterHandling];

        return $this;
    }

    public static function networkError(string $curlMessage): MockResponse
    {
        return new MockResponse('', ['error' => $curlMessage]);
    }

    public static function html(int $status = 200): MockResponse
    {
        return new MockResponse('<!DOCTYPE html><html><body>3x-ui login</body></html>', ['http_code' => $status, 'response_headers' => ['content-type' => 'text/html; charset=utf-8']]);
    }

    public static function envelope(bool $success, mixed $obj = null, string $msg = '', int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode(['success' => $success, 'msg' => $msg, 'obj' => $obj]), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }

    /**
     * @return list<string> "METHOD path" of every request, in order
     */
    public function requestLines(): array
    {
        return array_map(static fn (array $r): string => $r['method'].' '.$r['path'], $this->requests);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        $parts = parse_url($url);
        $origin = sprintf('%s://%s%s', $parts['scheme'] ?? '', $parts['host'] ?? '', isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_contains((string) ($parts['host'] ?? ''), ':')) {
            $origin = sprintf('%s://[%s]%s', $parts['scheme'] ?? '', $parts['host'], isset($parts['port']) ? ':'.$parts['port'] : '');
        }
        $fullPath = (string) ($parts['path'] ?? '/');
        parse_str((string) ($parts['query'] ?? ''), $query);
        $headers = [];
        foreach ($options['normalized_headers'] ?? [] as $name => $lines) {
            // Symfony keeps whole "Name: value" lines here.
            $headers[$name] = array_map(static fn (string $line): string => (string) preg_replace('/^[^:]+:\s*/', '', $line), $lines);
        }
        $body = $this->decodeBody($options);

        $path = str_starts_with($fullPath, $this->basePath.'/') ? substr($fullPath, strlen($this->basePath) + 1) : null;
        $this->requests[] = ['method' => $method, 'path' => $path ?? $fullPath, 'query' => $query, 'headers' => $headers, 'body' => $body];

        foreach ($this->scripts as $i => $script) {
            if ((null === $script['method'] || $script['method'] === $method) && (null === $script['path'] || $script['path'] === $path)) {
                if (--$this->scripts[$i]['times'] <= 0) {
                    unset($this->scripts[$i]);
                }
                if ($script['afterHandling']) {
                    $this->handle($method, $path, $query, $headers, $body);
                }
                $response = $script['response'];

                return $response instanceof \Closure ? $response($method, $url, $options) : $response;
            }
        }

        if ($origin !== $this->origin) {
            return self::networkError(sprintf('Failed to connect to %s port %d: Connection refused', $parts['host'] ?? '?', $parts['port'] ?? 443));
        }
        if (null === $path) {
            return new MockResponse('404 page not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/plain']]);
        }

        return $this->handle($method, $path, $query, $headers, $body);
    }

    /**
     * @param array<string, string>       $query
     * @param array<string, list<string>> $headers
     */
    private function handle(string $method, string $path, array $query, array $headers, mixed $body): MockResponse
    {
        if ('POST' === $method && 'login' === $path) {
            $ok = is_array($body) && self::USERNAME === ($body['username'] ?? null) && self::PASSWORD === ($body['password'] ?? null);
            if (!$ok) {
                return self::envelope(false, null, 'Invalid username or password');
            }
            $this->session = bin2hex(random_bytes(8));

            return new MockResponse((string) json_encode(['success' => true, 'msg' => 'Login Successfully', 'obj' => null]), [
                'response_headers' => ['content-type' => 'application/json', 'set-cookie' => '3x-ui='.$this->session.'; Path=/; HttpOnly'],
            ]);
        }
        if (!str_starts_with($path, 'panel/api/')) {
            return self::html(404);
        }

        $bearer = (string) ($headers['authorization'][0] ?? '');
        $cookie = (string) ($headers['cookie'][0] ?? '');
        $hasSession = null !== $this->session && str_contains($cookie, '3x-ui='.$this->session);
        $api = substr($path, strlen('panel/api/'));

        if ('legacy' === $this->mode) {
            // Old panels: no clients API, no bearer tokens; unauthenticated API calls get the 404 page.
            if (str_starts_with($api, 'clients/') || !$hasSession) {
                return new MockResponse('404 page not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/plain']]);
            }
        } elseif (!$hasSession) {
            if ('' === $bearer) {
                return self::html(401);
            }
            if ('Bearer '.$this->token !== $bearer || $this->tokenRevoked) {
                return self::envelope(false, null, 'unauthorized', 401);
            }
        }

        return $this->route($method, $api, $query, $body);
    }

    /**
     * @param array<string, string> $query
     */
    private function route(string $method, string $api, array $query, mixed $body): MockResponse
    {
        return match (true) {
            'GET' === $method && 'inbounds/list' === $api => self::envelope(true, array_values(array_map(fn (array $i): array => $i + ['clientStats' => $this->clientStatsFor((int) $i['id'])], $this->inbounds))),
            'GET' === $method && 'inbounds/options' === $api => self::envelope(true, array_values(array_map(static fn (array $i): array => array_intersect_key($i, array_flip(['id', 'remark', 'tag', 'protocol', 'port', 'enable', 'tlsFlowCapable', 'ssMethod', 'nodeId'])), $this->inbounds))),
            'GET' === $method && 'clients/list' === $api => self::envelope(true, array_values(array_map(fn (array $c): array => $this->clientView($c), $this->clients))),
            'GET' === $method && 'clients/list/paged' === $api => $this->paged($query),
            default => new MockResponse('404 page not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/plain']]),
        };
    }

    /**
     * @param array<string, string> $query
     */
    private function paged(array $query): MockResponse
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $size = (int) ($query['pageSize'] ?? 50);
        if ($size < 1 || $size > 200) {
            return self::envelope(false, null, 'pageSize must be between 1 and 200');
        }
        $rows = array_values(array_map(fn (array $c): array => $this->clientView($c), $this->clients));
        $search = (string) ($query['search'] ?? '');
        if ('' !== $search) {
            $rows = array_values(array_filter($rows, static fn (array $c): bool => str_contains((string) $c['email'], $search)));
        }

        return self::envelope(true, ['items' => array_slice($rows, ($page - 1) * $size, $size), 'total' => count($rows), 'page' => $page, 'pageSize' => $size]);
    }

    /**
     * @param array<string, mixed> $client
     *
     * @return array<string, mixed>
     */
    private function clientView(array $client): array
    {
        $t = $this->traffic[$client['email']] ?? ['up' => 0, 'down' => 0, 'lastOnline' => 0];

        return $client + ['up' => $t['up'], 'down' => $t['down'], 'lastOnline' => $t['lastOnline']];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function clientStatsFor(int $inboundId): array
    {
        $stats = [];
        foreach ($this->clients as $client) {
            if (in_array($inboundId, (array) ($client['inboundIds'] ?? []), true)) {
                $t = $this->traffic[$client['email']] ?? ['up' => 0, 'down' => 0, 'lastOnline' => 0];
                $stats[] = ['email' => $client['email'], 'up' => $t['up'], 'down' => $t['down'], 'total' => $client['totalGB'], 'expiryTime' => $client['expiryTime'], 'enable' => $client['enable']];
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function decodeBody(array $options): mixed
    {
        $raw = $options['body'] ?? '';
        if ($raw instanceof \Closure) {
            $raw = '';
        }
        $raw = (string) $raw;
        if ('' === $raw) {
            return null;
        }
        $json = json_decode($raw, true);
        if (null !== $json) {
            return $json;
        }
        parse_str($raw, $form);

        return $form;
    }
}
