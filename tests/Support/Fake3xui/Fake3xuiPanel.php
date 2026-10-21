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

    /** Client fields the panel stores; an update REPLACES the row with these (missing ones fall back to defaults). */
    public const CLIENT_FIELDS = ['email', 'id', 'subId', 'flow', 'totalGB', 'expiryTime', 'limitIp', 'limitHwid', 'tgId', 'comment', 'enable', 'group', 'reset'];

    /**
     * @param array<string, string> $query
     */
    private function route(string $method, string $api, array $query, mixed $body): MockResponse
    {
        $body = is_array($body) ? $body : [];
        $notFound = static fn (): MockResponse => self::envelope(false, null, 'client not found');
        if ('GET' === $method && 1 === preg_match('#^clients/get/tgId/(\d+)$#', $api, $m)) {
            return self::envelope(true, array_values(array_map(fn (array $c): array => $this->rowView($c), array_filter($this->clients, static fn (array $c): bool => (int) $c['tgId'] === (int) $m[1]))));
        }
        if (1 === preg_match('#^clients/(get|traffic|links|del|update|resetTraffic|updateTraffic)/(.+)$#', $api, $m) || 1 === preg_match('#^clients/(.+)/(attach|detach)$#', $api, $m2)) {
            [$action, $email] = isset($m2) && [] !== $m2 ? [$m2[2], rawurldecode($m2[1])] : [$m[1], rawurldecode($m[2])];
            $key = $this->findEmail($email);

            return match (true) {
                'GET' === $method && 'get' === $action => null === $key ? $notFound() : self::envelope(true, $this->rowView($this->clients[$key])),
                'GET' === $method && 'traffic' === $action => null === $key ? $notFound() : self::envelope(true, $this->trafficView($this->clients[$key])),
                'GET' === $method && 'links' === $action => null === $key ? $notFound() : self::envelope(true, $this->linksFor($this->clients[$key])),
                'POST' === $method && 'del' === $action => $this->delete($key, '1' === ($query['keepTraffic'] ?? '0')),
                'POST' === $method && 'update' === $action => null === $key ? $notFound() : $this->update($key, $body),
                'POST' === $method && 'resetTraffic' === $action => null === $key ? $notFound() : $this->resetTraffic($key),
                'POST' === $method && 'updateTraffic' === $action => null === $key ? $notFound() : $this->setTraffic($key, (int) ($body['upload'] ?? 0), (int) ($body['download'] ?? 0)),
                'POST' === $method && in_array($action, ['attach', 'detach'], true) => null === $key ? $notFound() : $this->attach($key, (array) ($body['inboundIds'] ?? []), 'attach' === $action),
                default => self::notFoundPage(),
            };
        }
        if ('GET' === $method && 1 === preg_match('#^clients/subLinks/(.+)$#', $api, $m)) {
            $links = [];
            foreach ($this->clients as $client) {
                if ($client['subId'] === rawurldecode($m[1])) {
                    $links = [...$links, ...$this->linksFor($client)];
                }
            }

            return self::envelope(true, $links);
        }
        if ('GET' === $method && 1 === preg_match('#^inbounds/get/(\d+)$#', $api, $m)) {
            return isset($this->inbounds[(int) $m[1]]) ? self::envelope(true, $this->inbounds[(int) $m[1]]) : self::envelope(false, null, 'inbound not found');
        }

        return match (true) {
            'GET' === $method && 'inbounds/list' === $api => self::envelope(true, array_values(array_map(fn (array $i): array => $i + ['clientStats' => $this->clientStatsFor((int) $i['id'])], $this->inbounds))),
            'GET' === $method && 'inbounds/list/slim' === $api => self::envelope(true, array_values($this->inbounds)),
            'GET' === $method && 'inbounds/options' === $api => self::envelope(true, array_values(array_map(static fn (array $i): array => array_intersect_key($i, array_flip(['id', 'remark', 'tag', 'protocol', 'port', 'enable', 'tlsFlowCapable', 'ssMethod', 'nodeId'])), $this->inbounds))),
            'GET' === $method && 'clients/list' === $api => self::envelope(true, array_values(array_map(fn (array $c): array => $this->listView($c), $this->clients))),
            'GET' === $method && 'clients/list/paged' === $api => $this->paged($query),
            'POST' === $method && 'clients/add' === $api => $this->add($body),
            'POST' === $method && 'clients/onlines' === $api => self::envelope(true, array_values(array_map(static fn (array $c): string => $c['email'], array_filter($this->clients, fn (array $c): bool => ($this->traffic[$c['email']]['lastOnline'] ?? 0) > (time() - 60) * 1000)))),
            'POST' === $method && 'clients/lastOnline' === $api => self::envelope(true, array_map(static fn (array $t): int => $t['lastOnline'], $this->traffic)),
            'POST' === $method && 'clients/bulkEnable' === $api, 'POST' === $method && 'clients/bulkDisable' === $api => $this->bulkEnable((array) ($body['emails'] ?? []), 'clients/bulkEnable' === $api),
            'POST' === $method && 'clients/bulkDel' === $api => $this->bulkDelete((array) ($body['emails'] ?? [])),
            'POST' === $method && 'clients/bulkResetTraffic' === $api => $this->bulkResetTraffic((array) ($body['emails'] ?? [])),
            default => self::notFoundPage(),
        };
    }

    /**
     * The panel's periodic traffic/expiry loop: disables clients that are out of traffic or past expiry.
     */
    public function runTrafficLoop(): void
    {
        foreach ($this->clients as $key => $client) {
            $t = $this->traffic[$client['email']] ?? ['up' => 0, 'down' => 0, 'lastOnline' => 0];
            $depleted = $client['totalGB'] > 0 && $t['up'] + $t['down'] >= $client['totalGB'];
            $expired = $client['expiryTime'] > 0 && $client['expiryTime'] <= time() * 1000;
            if ($depleted || $expired) {
                $this->clients[$key]['enable'] = false;
            }
        }
    }

    public function useTraffic(string $email, int $bytes): void
    {
        $t = $this->traffic[$email] ?? ['up' => 0, 'down' => 0, 'lastOnline' => 0];
        $this->traffic[$email] = ['up' => $t['up'] + intdiv($bytes, 4), 'down' => $t['down'] + $bytes - intdiv($bytes, 4), 'lastOnline' => time() * 1000];
    }

    private static function notFoundPage(): MockResponse
    {
        return new MockResponse('404 page not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/plain']]);
    }

    private function findEmail(string $email): ?string
    {
        foreach ($this->clients as $key => $client) {
            if (0 === strcasecmp($client['email'], $email)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body {client: {...}, inboundIds: [...]}
     */
    private function add(array $body): MockResponse
    {
        $client = $body['client'] ?? null;
        $inboundIds = array_map('intval', (array) ($body['inboundIds'] ?? []));
        if (!is_array($client) || '' === (string) ($client['email'] ?? '')) {
            return self::envelope(false, null, 'client.email is required');
        }
        if ([] === $inboundIds) {
            return self::envelope(false, null, 'at least one inbound is required');
        }
        foreach ($inboundIds as $id) {
            if (!isset($this->inbounds[$id])) {
                return self::envelope(false, null, 'inbound '.$id.' not found');
            }
        }
        if (null !== $this->findEmail((string) $client['email'])) {
            return self::envelope(false, null, 'Duplicate email: '.$client['email']);
        }
        $row = $this->storedRow($client);
        $row['id'] = '' === (string) $row['id'] ? self::uuid() : $row['id'];
        $row['subId'] = '' === (string) $row['subId'] ? bin2hex(random_bytes(8)) : $row['subId'];
        $row['inboundIds'] = $inboundIds;
        $row['dbId'] = count($this->clients) + 1;
        $this->clients[$row['email']] = $row;
        $this->traffic[$row['email']] = ['up' => 0, 'down' => 0, 'lastOnline' => 0];

        return self::envelope(true, null, 'Client added');
    }

    /**
     * Replaces the row (documented behaviour): fields missing from the body fall back to defaults, the body is the bare
     * client (not wrapped in "client"), the inbound attachment stays.
     *
     * @param array<string, mixed> $body
     */
    private function update(string $key, array $body): MockResponse
    {
        if (isset($body['client']) || 0 !== strcasecmp((string) ($body['email'] ?? ''), $this->clients[$key]['email'])) {
            return self::envelope(false, null, 'invalid client payload');
        }
        $row = $this->storedRow($body);
        if ('' === (string) $row['id']) {
            $row['id'] = self::uuid(); // a replacement without the UUID gives the client new credentials
        }
        $row['inboundIds'] = $this->clients[$key]['inboundIds'];
        $row['dbId'] = $this->clients[$key]['dbId'];
        $this->clients[$key] = $row;

        return self::envelope(true, null, 'Client updated');
    }

    private function delete(?string $key, bool $keepTraffic): MockResponse
    {
        if (null === $key) {
            return self::envelope(false, null, 'client not found');
        }
        $email = $this->clients[$key]['email'];
        unset($this->clients[$key]);
        if (!$keepTraffic) {
            unset($this->traffic[$email]);
        }

        return self::envelope(true, null, 'Client deleted');
    }

    private function resetTraffic(string $key): MockResponse
    {
        $email = $this->clients[$key]['email'];
        $this->traffic[$email] = ['up' => 0, 'down' => 0, 'lastOnline' => $this->traffic[$email]['lastOnline'] ?? 0];

        return self::envelope(true);
    }

    private function setTraffic(string $key, int $up, int $down): MockResponse
    {
        $email = $this->clients[$key]['email'];
        $this->traffic[$email] = ['up' => $up, 'down' => $down, 'lastOnline' => $this->traffic[$email]['lastOnline'] ?? 0];

        return self::envelope(true);
    }

    /**
     * @param array<mixed> $ids
     */
    private function attach(string $key, array $ids, bool $attach): MockResponse
    {
        $ids = array_map('intval', $ids);
        $current = $this->clients[$key]['inboundIds'];
        $this->clients[$key]['inboundIds'] = array_values($attach ? array_unique([...$current, ...$ids]) : array_diff($current, $ids));

        return self::envelope(true);
    }

    /**
     * @param array<mixed> $emails
     */
    private function bulkEnable(array $emails, bool $enable): MockResponse
    {
        $changed = [];
        $skipped = [];
        foreach ($emails as $email) {
            $key = $this->findEmail((string) $email);
            if (null === $key) {
                $skipped[] = ['email' => $email, 'reason' => 'client not found'];
                continue;
            }
            $this->clients[$key]['enable'] = $enable;
            $changed[] = $email;
        }

        return self::envelope(true, ['changed' => $changed, 'skipped' => $skipped]);
    }

    /**
     * @param array<mixed> $emails
     */
    private function bulkDelete(array $emails): MockResponse
    {
        $deleted = [];
        foreach ($emails as $email) {
            $key = $this->findEmail((string) $email);
            if (null !== $key) {
                $this->delete($key, false);
                $deleted[] = $email;
            }
        }

        return self::envelope(true, ['deleted' => $deleted, 'skipped' => []]);
    }

    /**
     * @param array<mixed> $emails
     */
    private function bulkResetTraffic(array $emails): MockResponse
    {
        $n = 0;
        foreach ($emails as $email) {
            $key = $this->findEmail((string) $email);
            if (null !== $key) {
                $this->resetTraffic($key);
                ++$n;
            }
        }

        return self::envelope(true, ['affected' => $n]);
    }

    /**
     * @param array<string, mixed> $client
     *
     * @return array<string, mixed>
     */
    private function storedRow(array $client): array
    {
        $defaults = ['email' => '', 'id' => '', 'subId' => '', 'flow' => '', 'totalGB' => 0, 'expiryTime' => 0, 'limitIp' => 0, 'limitHwid' => 0, 'tgId' => 0, 'comment' => '', 'enable' => true, 'group' => '', 'reset' => 0];

        return array_replace($defaults, array_intersect_key($client, $defaults));
    }

    /**
     * get/{email}: the stored client in the add shape (id = UUID), with its inbounds.
     *
     * @param array<string, mixed> $client
     *
     * @return array<string, mixed>
     */
    private function rowView(array $client): array
    {
        return array_diff_key($client, ['dbId' => true]);
    }

    /**
     * list rows (documented): "id" is the database row id, the UUID is in "uuid", traffic {up, down, enable}.
     *
     * @param array<string, mixed> $client
     *
     * @return array<string, mixed>
     */
    private function listView(array $client): array
    {
        $t = $this->traffic[$client['email']] ?? ['up' => 0, 'down' => 0, 'lastOnline' => 0];

        return ['id' => $client['dbId'], 'email' => $client['email'], 'subId' => $client['subId'], 'uuid' => $client['id'], 'totalGB' => $client['totalGB'],
            'expiryTime' => $client['expiryTime'], 'enable' => $client['enable'], 'reverse' => false, 'inboundIds' => $client['inboundIds'],
            'traffic' => ['up' => $t['up'], 'down' => $t['down'], 'enable' => $client['enable']]];
    }

    /**
     * @param array<string, mixed> $client
     *
     * @return array<string, mixed>
     */
    private function trafficView(array $client): array
    {
        $t = $this->traffic[$client['email']] ?? ['up' => 0, 'down' => 0, 'lastOnline' => 0];

        return ['up' => $t['up'], 'down' => $t['down'], 'total' => $client['totalGB'], 'expiryTime' => $client['expiryTime'], 'enable' => $client['enable'],
            'lastOnline' => $t['lastOnline'], 'subId' => $client['subId'], 'uuid' => $client['id']];
    }

    /**
     * @param array<string, mixed> $client
     *
     * @return list<string>
     */
    private function linksFor(array $client): array
    {
        $links = [];
        foreach ($client['inboundIds'] as $id) {
            $inbound = $this->inbounds[$id] ?? null;
            if (null !== $inbound) {
                $links[] = sprintf('%s://%s@panel.example.test:%d?type=tcp#%s', $inbound['protocol'], $client['id'], $inbound['port'], rawurlencode($client['email']));
            }
        }

        return $links;
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return sprintf('%s-%s-4%s-a%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));
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
        $rows = array_values(array_map(fn (array $c): array => array_diff_key($this->rowView($c), ['id' => true, 'flow' => true, 'tgId' => true]) + ['traffic' => null], $this->clients));
        $search = (string) ($query['search'] ?? '');
        if ('' !== $search) {
            $rows = array_values(array_filter($rows, static fn (array $c): bool => str_contains((string) $c['email'], $search)));
        }

        return self::envelope(true, ['items' => array_slice($rows, ($page - 1) * $size, $size), 'total' => count($rows), 'page' => $page, 'pageSize' => $size]);
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
