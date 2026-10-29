<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern;

use App\Entity\VpnPanel;
use App\Provisioning\Domain\ShareLinkPolicy;
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

    /**
     * The JSON types of 3x-ui's model.Client, which clients/add and clients/update unmarshal the client into
     * (MHSanaei/3x-ui internal/database/model/model.go). GET answers with the database record (model.ClientRecord)
     * instead, where allowedIPs and reverse are strings: sent back unchanged, Go refuses the whole body
     * ("json: cannot unmarshal string into Go struct field .allowedIPs of type []string"). limitHwid exists only in the
     * record; it is sent as a number and ignored by Go like any unknown field. Fields not listed pass through as they are.
     */
    private const CLIENT_FIELD_TYPES = [
        'id' => 'string', 'security' => 'string', 'password' => 'string', 'flow' => 'string', 'auth' => 'string', 'privateKey' => 'string',
        'publicKey' => 'string', 'preSharedKey' => 'string', 'forwardedPorts' => 'string', 'secret' => 'string', 'adTag' => 'string',
        'email' => 'string', 'subId' => 'string', 'group' => 'string', 'comment' => 'string', 'trafficReset' => 'string',
        'limitIp' => 'int', 'limitHwid' => 'int', 'totalGB' => 'int', 'expiryTime' => 'int', 'tgId' => 'int', 'reset' => 'int', 'resetDay' => 'int',
        'resetWeekday' => 'int', 'resetMax' => 'int', 'trafficResetDay' => 'int', 'keepAlive' => 'int',
        'enable' => 'bool',
        'allowedIPs' => 'list',
        'allowedIPsByInbound' => 'map',
        'reverse' => 'object',
    ];

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
        try {
            return $this->http->post($panel, 'panel/api/clients/add', ['client' => self::toClientModel($client), 'inboundIds' => $inboundIds]);
        } catch (PanelApiException $e) {
            throw self::withFieldTypeHint($e, 'POST panel/api/clients/add');
        }
    }

    /**
     * POST panel/api/clients/update/{email}. The panel REPLACES the row with the body (it does not patch), so the body
     * is the full client: callers GET it first and merge (mergeForUpdate).
     *
     * @param array<string, mixed> $fullClient
     */
    public function updateClient(VpnPanel $panel, string $email, array $fullClient): PanelResponse
    {
        try {
            return $this->http->post($panel, 'panel/api/clients/update/'.rawurlencode($email), self::toClientModel($fullClient));
        } catch (PanelApiException $e) {
            throw self::withFieldTypeHint($e, 'POST panel/api/clients/update/'.$email);
        }
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

    /** The largest link list accepted from the panel (ShareLinkPolicy caps the count, this the bytes). */
    public const LINKS_MAX_BYTES = 1048576;

    /**
     * Share links of one client (GET links/{email}) or of a subscription (GET subLinks/{subId}: the same links, in the
     * same order, as the subscription the customer's app fetches, with Hosts applied). Only items ShareLinkPolicy
     * accepts are returned; the others are counted in "rejected". The subscription id never appears in a log or error
     * (it is a credential: it opens the subscription).
     *
     * @param bool $recordHealth false: a failure is not counted towards the panel's "degraded" state (bulk refreshes)
     *
     * @return array{links: list<string>, rejected: int, rejectedSchemes: array<string, int>}
     */
    public function links(VpnPanel $panel, string $email, bool $recordHealth = true): array
    {
        return ShareLinkPolicy::filter($this->http->get($panel, 'panel/api/clients/links/'.rawurlencode($email), [], self::LINKS_MAX_BYTES, null, $recordHealth)->obj);
    }

    /**
     * @return array{links: list<string>, rejected: int, rejectedSchemes: array<string, int>}
     */
    public function subLinks(VpnPanel $panel, string $subId, bool $recordHealth = true): array
    {
        return ShareLinkPolicy::filter($this->http->get($panel, 'panel/api/clients/subLinks/'.rawurlencode($subId), [], self::LINKS_MAX_BYTES, 'panel/api/clients/subLinks/{subId}', $recordHealth)->obj);
    }

    /**
     * GET panel/api/hosts/list: whether the panel has the Hosts API (3x-ui v3.11+), reported by app:panel:doctor and
     * app:service:verify-links only, never a switch for link handling. Decided from the answer, never a version string:
     * success with a list = "yes", the bare 404 page = "no", 401/403 = "forbidden" (the token's scope does not cover it).
     *
     * @return array{state: 'yes'|'no'|'forbidden'|'unknown', count: int, detail: ?string}
     */
    public function hostsCapability(VpnPanel $panel): array
    {
        try {
            $obj = $this->http->get($panel, 'panel/api/hosts/list', [], self::LINKS_MAX_BYTES)->obj;
        } catch (PanelApiException $e) {
            return match ($e->failure) {
                PanelFailure::WRONG_URL => ['state' => 'no', 'count' => 0, 'detail' => null],
                PanelFailure::AUTH_REJECTED => ['state' => 'forbidden', 'count' => 0, 'detail' => null],
                default => ['state' => 'unknown', 'count' => 0, 'detail' => $e->failure->value],
            };
        }
        if (null !== $obj && (!is_array($obj) || !array_is_list($obj))) {
            return ['state' => 'unknown', 'count' => 0, 'detail' => PanelFailure::UNEXPECTED_RESPONSE->value];
        }

        return ['state' => 'yes', 'count' => count($obj ?? []), 'detail' => null];
    }

    /**
     * GET panel/api/hosts/byInbound/{id}: the host groups attached to one inbound (each with inboundIds, hosts[] = its
     * addresses, isDisabled, excludeFromSubTypes, ...). Read only by app:service:verify-links.
     *
     * @return list<array<string, mixed>>
     */
    public function hostsByInbound(VpnPanel $panel, int $inboundId): array
    {
        $obj = $this->http->get($panel, 'panel/api/hosts/byInbound/'.$inboundId, [], self::LINKS_MAX_BYTES)->obj;
        if (null === $obj) {
            return [];
        }
        if (!is_array($obj) || !array_is_list($obj)) {
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, 'GET panel/api/hosts/byInbound: obj is not a list.');
        }

        return array_values(array_filter($obj, 'is_array'));
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
     * minus read-only fields, with the UUID under "id", and $changes applied on top, every field in the type the
     * update endpoint expects (toClientModel). Every update of an existing client goes through here (updateAndVerify).
     *
     * @param array<string, mixed> $current a normalised client from getClient()
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public static function mergeForUpdate(array $current, array $changes): array
    {
        return self::toClientModel(array_replace(array_diff_key($current, array_flip(self::READ_ONLY_FIELDS)), $changes));
    }

    /**
     * The client in the JSON types of model.Client (CLIENT_FIELD_TYPES), so a row read with GET can always be written
     * back: allowedIPs "" / null -> [], "a, b" or '["a","b"]' -> ["a", "b"]; numbers given as strings -> int; "true" / 1 ->
     * true; numbers in string fields -> strings; reverse as JSON text -> object, "" -> left out (omitempty). A value
     * that cannot be converted is left as it is: the panel's answer then names the field (withFieldTypeHint).
     *
     * @param array<string, mixed> $client
     *
     * @return array<string, mixed>
     */
    public static function toClientModel(array $client): array
    {
        foreach (self::CLIENT_FIELD_TYPES as $field => $type) {
            if (!array_key_exists($field, $client)) {
                continue;
            }
            $value = $client[$field];
            if ('list' === $type) {
                $client[$field] = self::stringList($value);
                continue;
            }
            if (null === $value) {
                continue; // Go accepts null for every type (zero value / nil)
            }
            if ('object' === $type || 'map' === $type) {
                $decoded = is_string($value) ? json_decode($value, true) : $value;
                if (is_array($decoded) && ([] === $decoded || !array_is_list($decoded))) {
                    $client[$field] = $decoded;
                } elseif ('' === $value || [] === $value || (is_string($value) && 'null' === strtolower(trim($value)))) {
                    unset($client[$field]); // nothing set: the field is omitempty
                }
                continue;
            }
            $client[$field] = match ($type) {
                'string' => is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : '') : (string) $value) : $value,
                'int' => match (true) {
                    is_int($value) => $value,
                    is_bool($value) => (int) $value,
                    is_float($value) && floor($value) === $value => (int) $value,
                    is_string($value) && '' === trim($value) => 0,
                    is_string($value) && 1 === preg_match('/^\s*-?\d+(\.0+)?\s*$/', $value) => (int) $value,
                    default => $value,
                },
                'bool' => match (true) {
                    is_bool($value) => $value,
                    is_int($value), is_float($value) => 0 != $value,
                    is_string($value) => in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true),
                    default => $value,
                },
            };
        }

        return $client;
    }

    /**
     * When the panel refused a body because of a JSON type (Go: "cannot unmarshal X into Go struct field .F of type T"),
     * the same failure with the field, the expected and the received type up front. Other failures are returned as they are.
     */
    public static function withFieldTypeHint(PanelApiException $e, string $what): PanelApiException
    {
        if (PanelFailure::PANEL_REJECTED !== $e->failure
            || 1 !== preg_match('/cannot unmarshal (\w+)(?: [^ ]+)? into Go (?:struct field|value of type) ([\w.]*?)\.?(\w+) of type (\S+)/', (string) $e->panelMessage, $m)) {
            return $e;
        }

        return new PanelApiException(
            $e->failure,
            sprintf('%s: the panel rejected client field "%s": it expects %s, got %s (panel: %s)', $what, $m[3], $m[4], $m[1], $e->panelMessage),
            $e->httpStatus,
            $e->panelMessage,
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = str_starts_with(trim($value), '[') ? json_decode($value, true) : null;
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '', $value), static fn (string $v): bool => '' !== $v));
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
}
