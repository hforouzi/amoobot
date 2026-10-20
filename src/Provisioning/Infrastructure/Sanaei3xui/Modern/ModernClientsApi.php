<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern;

use App\Entity\VpnPanel;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\ModernPanelHttpClient;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelResponse;

/**
 * The 3x-ui clients API (clients are first-class; inbound endpoints are never used to manage them: updating an
 * inbound ignores settings.clients and enable). Units on the wire: totalGB in bytes (0 = unlimited), expiryTime in
 * Unix milliseconds (0 = unlimited, negative = a duration that starts at first use).
 *
 * Several answers are documented only as obj: unknown/null, so rows are read tolerantly (normaliseClient) and every
 * write is checked by the caller with a follow-up GET rather than by trusting obj.
 */
class ModernClientsApi
{
    public const PAGE_SIZE_MAX = 200;

    /** Fields the panel computes or returns for reading only: never sent back in an update. */
    private const READ_ONLY_FIELDS = ['traffic', 'up', 'down', 'allTime', 'lastOnline', 'createdAt', 'updatedAt', 'inboundIds', 'uuid', 'dbId', 'clientStats'];

    public function __construct(private readonly ModernPanelHttpClient $http)
    {
    }

    /**
     * GET panel/api/clients/get/{email}.
     *
     * @return array<string, mixed>|null the client (normalised), null when the panel says there is no such client
     *
     * @throws PanelApiException when the panel cannot answer (unknown is never reported as "absent")
     */
    public function getClient(VpnPanel $panel, string $email): ?array
    {
        try {
            $response = $this->http->get($panel, 'panel/api/clients/get/'.rawurlencode($email));
        } catch (PanelApiException $e) {
            if (self::isNotFound($e)) {
                return null;
            }

            throw $e;
        }
        if (null === $response->obj || [] === $response->obj) {
            return null;
        }
        if (!is_array($response->obj)) {
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, 'GET panel/api/clients/get: obj is not a client object.', $response->httpStatus);
        }
        $client = self::normaliseClient($response->obj);
        if (0 !== strcasecmp((string) ($client['email'] ?? ''), $email)) {
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, 'GET panel/api/clients/get: the answer is not the requested client.', $response->httpStatus);
        }

        return $client;
    }

    /**
     * GET panel/api/clients/list: every client with its traffic (up/down) in one request.
     *
     * @return array<string, array<string, mixed>> normalised clients by lower-cased email
     */
    public function listClients(VpnPanel $panel): array
    {
        $response = $this->http->get($panel, 'panel/api/clients/list');
        if (!is_array($response->obj) || !array_is_list($response->obj)) {
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, 'GET panel/api/clients/list: obj is not a list.', $response->httpStatus);
        }
        $clients = [];
        foreach ($response->obj as $row) {
            if (is_array($row) && '' !== (string) ($row['email'] ?? '')) {
                $client = self::normaliseClient($row);
                $clients[strtolower((string) $client['email'])] = $client;
            }
        }

        return $clients;
    }

    /**
     * GET panel/api/clients/list/paged (pageSize at most 200).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listPaged(VpnPanel $panel, int $page = 1, int $pageSize = self::PAGE_SIZE_MAX, string $search = ''): array
    {
        $query = ['page' => max(1, $page), 'pageSize' => min(self::PAGE_SIZE_MAX, max(1, $pageSize))];
        if ('' !== $search) {
            $query['search'] = $search;
        }
        $obj = $this->http->get($panel, 'panel/api/clients/list/paged', $query)->obj;
        if (!is_array($obj) || !is_array($obj['items'] ?? null)) {
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, 'GET panel/api/clients/list/paged: obj.items is missing.');
        }
        $items = array_values(array_map(static fn (mixed $row): array => self::normaliseClient(is_array($row) ? $row : []), $obj['items']));

        return ['items' => $items, 'total' => (int) ($obj['total'] ?? count($items))];
    }

    /**
     * POST panel/api/clients/add {client, inboundIds}. The panel generates secrets that are not given; the bot always
     * sends id (UUID) so a retried create can find and adopt the client by UUID.
     *
     * @param array<string, mixed> $client
     * @param list<int>            $inboundIds
     */
    public function addClient(VpnPanel $panel, array $client, array $inboundIds): PanelResponse
    {
        return $this->http->post($panel, 'panel/api/clients/add', ['client' => $client, 'inboundIds' => $inboundIds]);
    }

    /**
     * POST panel/api/clients/update/{email}. The panel REPLACES the row with the body (it does not patch), so the body
     * is the full client: callers GET it first and merge (mergeForUpdate).
     *
     * @param array<string, mixed> $fullClient
     */
    public function updateClient(VpnPanel $panel, string $email, array $fullClient): PanelResponse
    {
        return $this->http->post($panel, 'panel/api/clients/update/'.rawurlencode($email), $fullClient);
    }

    /**
     * @return bool false when the client did not exist (already deleted)
     */
    public function deleteClient(VpnPanel $panel, string $email, bool $keepTraffic = false): bool
    {
        try {
            $this->http->post($panel, 'panel/api/clients/del/'.rawurlencode($email), null, ['keepTraffic' => $keepTraffic ? 1 : 0]);
        } catch (PanelApiException $e) {
            if (self::isNotFound($e)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    public function resetTraffic(VpnPanel $panel, string $email): void
    {
        $this->http->post($panel, 'panel/api/clients/resetTraffic/'.rawurlencode($email));
    }

    /**
     * @param list<int> $inboundIds
     */
    public function attach(VpnPanel $panel, string $email, array $inboundIds): void
    {
        $this->http->post($panel, 'panel/api/clients/'.rawurlencode($email).'/attach', ['inboundIds' => $inboundIds]);
    }

    /**
     * GET panel/api/clients/traffic/{email}: up, down, total, expiryTime, enable, lastOnline, subId, uuid.
     *
     * @return array<string, mixed>|null null when there is no such client
     */
    public function traffic(VpnPanel $panel, string $email): ?array
    {
        try {
            $obj = $this->http->get($panel, 'panel/api/clients/traffic/'.rawurlencode($email))->obj;
        } catch (PanelApiException $e) {
            if (self::isNotFound($e)) {
                return null;
            }

            throw $e;
        }
        if (null === $obj) {
            return null;
        }
        if (!is_array($obj)) {
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, 'GET panel/api/clients/traffic: obj is not an object.');
        }

        return $obj;
    }

    /**
     * Share links of one client (GET links/{email}) or of a subscription (GET subLinks/{subId}).
     *
     * @return list<string>
     */
    public function links(VpnPanel $panel, string $email): array
    {
        return self::extractLinks($this->http->get($panel, 'panel/api/clients/links/'.rawurlencode($email))->obj);
    }

    /**
     * @return list<string>
     */
    public function subLinks(VpnPanel $panel, string $subId): array
    {
        return self::extractLinks($this->http->get($panel, 'panel/api/clients/subLinks/'.rawurlencode($subId))->obj);
    }

    /**
     * GET panel/api/inbounds/options: the light inbound picker.
     *
     * @return list<array{id: int, remark: string, tag: string, protocol: string, port: int, enable: bool, tlsFlowCapable: bool, ssMethod: string, nodeId: int}>
     */
    public function inboundOptions(VpnPanel $panel): array
    {
        $response = $this->http->get($panel, 'panel/api/inbounds/options');
        if (!is_array($response->obj) || !array_is_list($response->obj)) {
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, 'GET panel/api/inbounds/options: obj is not a list.', $response->httpStatus);
        }
        $options = [];
        foreach ($response->obj as $row) {
            if (!is_array($row) || !is_numeric($row['id'] ?? null)) {
                continue;
            }
            $options[] = [
                'id' => (int) $row['id'],
                'remark' => (string) ($row['remark'] ?? ''),
                'tag' => (string) ($row['tag'] ?? ''),
                'protocol' => (string) ($row['protocol'] ?? ''),
                'port' => (int) ($row['port'] ?? 0),
                'enable' => (bool) ($row['enable'] ?? true),
                'tlsFlowCapable' => (bool) ($row['tlsFlowCapable'] ?? false),
                'ssMethod' => (string) ($row['ssMethod'] ?? ''),
                'nodeId' => (int) ($row['nodeId'] ?? 0),
            ];
        }

        return $options;
    }

    /**
     * Full update body: everything the panel returned (so fields the bot does not know survive the row replacement),
     * minus read-only fields, with the UUID under "id", and $changes applied on top.
     *
     * @param array<string, mixed> $current a normalised client from getClient()
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public static function mergeForUpdate(array $current, array $changes): array
    {
        return array_replace(array_diff_key($current, array_flip(self::READ_ONLY_FIELDS)), $changes);
    }

    /**
     * Accepts a bare client row or {client: {...}, inboundIds: [...]}. The UUID ends up in "id" (as in the add body):
     * list rows carry the database row id in "id" and the UUID in "uuid", so a "uuid" wins and the row id is kept as
     * the read-only "dbId". Casts the numeric fields the bot relies on.
     *
     * @param array<mixed> $raw
     *
     * @return array<string, mixed>
     */
    public static function normaliseClient(array $raw): array
    {
        $client = is_array($raw['client'] ?? null) ? $raw['client'] + array_diff_key($raw, ['client' => true]) : $raw;
        if ('' !== (string) ($client['uuid'] ?? '')) {
            if (isset($client['id']) && 0 !== strcasecmp((string) $client['id'], (string) $client['uuid'])) {
                $client['dbId'] = $client['id'];
            }
            $client['id'] = (string) $client['uuid'];
        }
        foreach (['totalGB', 'expiryTime', 'limitIp', 'limitHwid', 'tgId'] as $int) {
            if (isset($client[$int]) && is_numeric($client[$int])) {
                $client[$int] = (int) $client[$int];
            }
        }
        if (isset($client['enable'])) {
            $client['enable'] = (bool) $client['enable'];
        }
        if (isset($client['inboundIds']) && is_array($client['inboundIds'])) {
            $client['inboundIds'] = array_values(array_map('intval', array_filter($client['inboundIds'], 'is_numeric')));
        }

        return $client;
    }

    /**
     * Used bytes of a client row from /clients/list (traffic.up + traffic.down, or top-level up/down).
     *
     * @param array<string, mixed> $client
     */
    public static function usedBytes(array $client): ?int
    {
        $traffic = is_array($client['traffic'] ?? null) ? $client['traffic'] : $client;
        if (!isset($traffic['up']) && !isset($traffic['down'])) {
            return null;
        }

        return max(0, (int) ($traffic['up'] ?? 0) + (int) ($traffic['down'] ?? 0));
    }

    /**
     * "No such client" from a single-client endpoint: success:false with a not-found message, or 404 with the JSON
     * envelope (a 404 without it is a wrong URL and stays an error).
     */
    public static function isNotFound(PanelApiException $e): bool
    {
        if (PanelFailure::PANEL_REJECTED !== $e->failure) {
            return false;
        }

        return 404 === $e->httpStatus || 1 === preg_match('/not\s*found|no\s+such|does\s*n[o\']t\s*exist|not\s*exist/i', (string) $e->panelMessage);
    }

    /**
     * @return list<string>
     */
    private static function extractLinks(mixed $obj): array
    {
        if (is_string($obj)) {
            $obj = preg_split('/\R/', $obj) ?: [];
        }
        if (!is_array($obj)) {
            return [];
        }
        $links = [];
        array_walk_recursive($obj, static function (mixed $value) use (&$links): void {
            if (is_string($value) && 1 === preg_match('#^[a-z][a-z0-9+.-]*://\S+$#i', trim($value))) {
                $links[] = trim($value);
            }
        });

        return array_values(array_unique($links));
    }
}
