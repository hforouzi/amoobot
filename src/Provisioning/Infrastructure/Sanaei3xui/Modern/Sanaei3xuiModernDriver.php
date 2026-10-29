<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\BatchUsageDriverInterface;
use App\Provisioning\Domain\Dto\AdoptedVpnService;
use App\Provisioning\Domain\Dto\CreatedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\RenewVpnServiceRequest;
use App\Provisioning\Domain\Dto\VpnUsage;
use App\Provisioning\Domain\ClientOwnerComment;
use App\Provisioning\Domain\ClientOwnerDriverInterface;
use App\Provisioning\Domain\ClientOwnerNotApplicable;
use App\Provisioning\Domain\ClientUuid;
use App\Provisioning\Domain\CredentialRotationDriverInterface;
use App\Provisioning\Domain\Dto\PanelLinks;
use App\Provisioning\Domain\Dto\RotatedCredentials;
use App\Provisioning\Domain\ExistingClientAwareDriverInterface;
use App\Provisioning\Domain\PanelLinkSourceDriverInterface;
use App\Provisioning\Domain\ServiceActivationDriverInterface;
use App\Provisioning\Domain\TrafficLimitDriverInterface;
use App\Provisioning\Domain\VpnPanelDriverInterface;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteClientRef;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * 3x-ui driver on the modern clients API (bearer token). Selected for sanaei_3xui panels whose effective integration
 * mode is modern.
 *
 * - Clients are addressed by email; the remote id keeps the legacy format (panel, inbound, uuid, email, subId), so a
 *   panel can switch between legacy and modern without touching existing services.
 * - Every update GETs the client first and sends the FULL row (the panel replaces, it does not patch), with absolute
 *   values computed by the caller from stored state, so a retried call writes the same row again.
 * - Every write is verified with a follow-up GET; obj of a write answer is never trusted.
 * - create is idempotent: the UUID is sent explicitly (derived from the order), and after a failed or lost add the
 *   client is looked up and adopted when it carries that UUID.
 */
final class Sanaei3xuiModernDriver implements VpnPanelDriverInterface, ExistingClientAwareDriverInterface, TrafficLimitDriverInterface, ServiceActivationDriverInterface, BatchUsageDriverInterface, ClientOwnerDriverInterface, CredentialRotationDriverInterface, PanelLinkSourceDriverInterface
{
    public const BYTES_PER_GB = 1073741824;
    private const MS_PER_DAY = 86400000;

    public function __construct(
        private readonly ModernClientsApi $api,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(?VpnPanel $panel): bool
    {
        return $panel instanceof VpnPanel && 'sanaei_3xui' === $panel->getType() && $panel->isModern();
    }

    public function supportsProvisioning(?VpnPanel $panel): bool
    {
        return true;
    }

    public function createService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): CreatedVpnService
    {
        $panel = $this->requirePanel($panel);
        [$inbound, $remoteInboundId] = $this->requireInbound($request, $panel);
        $email = trim($request->username);
        if ('' === $email) {
            throw new \RuntimeException('Sanaei provisioning requires a client email/username.');
        }
        $uuid = $request->clientUuid ?? Uuid::v4()->toRfc4122();
        $client = [
            'email' => $email,
            'id' => $uuid,
            'subId' => bin2hex(random_bytes(8)),
            'flow' => $this->flowFor($inbound),
            'totalGB' => self::gbToBytes($request->trafficLimitGb),
            'expiryTime' => self::expiryMs($request->durationDays, true === ($request->meta['startOnFirstUse'] ?? false)),
            'limitIp' => max(0, (int) ($request->ipLimit ?? 0)),
            'limitHwid' => 0,
            'tgId' => is_numeric($request->meta['telegramId'] ?? null) ? (int) $request->meta['telegramId'] : 0,
            'comment' => isset($request->meta['orderId']) ? sprintf('amoobot order #%d', (int) $request->meta['orderId']) : 'amoobot',
            'enable' => true,
        ];

        try {
            $this->api->addClient($panel, $client, [$remoteInboundId]);
        } catch (PanelApiException $e) {
            // The add may have been applied and only its answer lost (timeout), or a previous attempt created it.
            // Adopt only a client that carries this order's UUID; anything else stays an error.
            if (PanelFailure::NOT_CONFIGURED === $e->failure || $e->failure->isUnreachable() && PanelFailure::NETWORK_TIMEOUT !== $e->failure) {
                throw $e;
            }
            $existing = $this->api->getClient($panel, $email);
            if (null === $existing) {
                throw $e;
            }
            if (0 !== strcasecmp((string) ($existing['id'] ?? ''), $uuid)) {
                throw new \RuntimeException(sprintf('A client named "%s" already exists on the panel with another UUID; resolve it on the panel, then retry.', $email), 0);
            }
            $this->logger->warning('sanaei_modern_create_adopted_after_failure', ['panel_id' => $panel->getId(), 'email' => $email, 'failure' => $e->failure->value]);
        }

        $created = $this->api->getClient($panel, $email);
        if (null === $created || 0 !== strcasecmp((string) ($created['id'] ?? ''), $uuid)) {
            throw new \RuntimeException(sprintf('The panel did not confirm client "%s" after adding it.', $email));
        }
        if (isset($created['inboundIds']) && !in_array($remoteInboundId, $created['inboundIds'], true)) {
            $this->api->attach($panel, $email, [$remoteInboundId]);
        }

        return $this->createdService($panel, $inbound, (string) $remoteInboundId, $created);
    }

    public function findExistingService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): ?AdoptedVpnService
    {
        $panel = $this->requirePanel($panel);
        [$inbound, $remoteInboundId] = $this->requireInbound($request, $panel);
        $email = trim($request->username);
        $uuid = (string) $request->clientUuid;

        $client = $this->api->getClient($panel, $email);
        $matchedBy = AdoptedVpnService::MATCHED_BY_EMAIL;
        if (null !== $client && '' !== $uuid && 0 === strcasecmp((string) ($client['id'] ?? ''), $uuid)) {
            $matchedBy = AdoptedVpnService::MATCHED_BY_UUID;
        } elseif (null === $client && '' !== $uuid) {
            // Renamed on the panel? The order's UUID still identifies it (one list request).
            foreach ($this->api->listClients($panel) as $candidate) {
                if (0 === strcasecmp((string) ($candidate['id'] ?? ''), $uuid)) {
                    $client = $this->api->getClient($panel, (string) $candidate['email']) ?? $candidate;
                    $matchedBy = AdoptedVpnService::MATCHED_BY_UUID;
                    break;
                }
            }
        }
        if (null === $client) {
            return null;
        }
        $this->logger->warning('sanaei_modern_existing_client_found', ['panel_id' => $panel->getId(), 'email' => $client['email'] ?? $email, 'matched_by' => $matchedBy]);

        $expiryMs = (int) ($client['expiryTime'] ?? 0);
        $totalBytes = (int) ($client['totalGB'] ?? 0);
        // Only a client that belongs to this order may be attached to the plan's inbound here; an email match on a
        // customer-chosen name is refused by the caller (VpnProvisioningService) before anything is changed.
        if (AdoptedVpnService::MATCHED_BY_UUID === $matchedBy && isset($client['inboundIds']) && !in_array($remoteInboundId, $client['inboundIds'], true)) {
            $this->api->attach($panel, (string) $client['email'], [$remoteInboundId]);
        }

        return new AdoptedVpnService(
            service: $this->createdService($panel, $inbound, (string) $remoteInboundId, $client),
            matchedBy: $matchedBy,
            expiresAt: $expiryMs > 0 ? self::msToDate($expiryMs) : null,
            trafficLimitGb: $totalBytes > 0 ? (int) round($totalBytes / self::BYTES_PER_GB) : null,
        );
    }

    public function renewService(string $remoteId, RenewVpnServiceRequest $request, ?VpnPanel $panel = null): void
    {
        $changes = ['enable' => true];
        if ($request->unlimitedDuration) {
            $changes['expiryTime'] = 0;
        } elseif ($request->expiresAt instanceof \DateTimeImmutable) {
            $changes['expiryTime'] = $request->expiresAt->getTimestamp() * 1000;
        } elseif ($request->durationDays > 0) {
            $changes['expiryTime'] = self::expiryMs($request->durationDays);
        }
        if (null !== $request->trafficLimitGb) {
            $changes['totalGB'] = self::gbToBytes($request->trafficLimitGb);
        }

        $this->updateAndVerify($this->requirePanel($panel), $this->ref($remoteId), $changes);
    }

    public function addTrafficLimit(string $remoteId, int $trafficLimitGb, ?VpnPanel $panel = null): void
    {
        $this->updateAndVerify($this->requirePanel($panel), $this->ref($remoteId), ['totalGB' => self::gbToBytes($trafficLimitGb)]);
    }

    public function suspendService(string $remoteId, ?VpnPanel $panel = null): void
    {
        $this->updateAndVerify($this->requirePanel($panel), $this->ref($remoteId), ['enable' => false]);
    }

    public function activateService(string $remoteId, ?VpnPanel $panel = null): void
    {
        $this->updateAndVerify($this->requirePanel($panel), $this->ref($remoteId), ['enable' => true]);
    }

    /**
     * tgId and comment only (claim links); credentials, limits, expiry and status are kept (updateAndVerify). The
     * comment keeps what an admin wrote there (ClientOwnerComment). A password-based client (Trojan, Shadowsocks) or
     * one whose id is not a UUID is refused before anything is sent (ClientOwnerNotApplicable: retrying cannot help).
     */
    public function updateClientOwner(string $remoteId, int $telegramId, string $comment, ?VpnPanel $panel = null): void
    {
        $panel = $this->requirePanel($panel);
        $ref = $this->ref($remoteId);
        $current = $this->api->getClient($panel, $ref->email);
        if (null === $current) {
            throw new \RuntimeException(sprintf('Client "%s" does not exist on the panel.', $ref->email));
        }
        $id = (string) ($current['id'] ?? '');
        if ('' === $id && '' !== (string) ($current['password'] ?? '')) {
            throw new ClientOwnerNotApplicable(sprintf('Client "%s" authenticates by password; its row is not replaced.', $ref->email));
        }
        if (!ClientUuid::isUuid($id)) {
            throw new ClientOwnerNotApplicable(sprintf('The panel reports an id for client "%s" that is not a UUID; its row is not replaced.', $ref->email));
        }
        $this->updateAndVerify($panel, $ref, ['tgId' => max(0, $telegramId), 'comment' => ClientOwnerComment::compose(isset($current['comment']) ? (string) $current['comment'] : null, $comment)]);
    }

    public function canRotateCredentials(string $remoteId, ?VpnPanel $panel = null): bool
    {
        return $this->supports($panel) && null !== $this->remoteIdParser->parse($remoteId);
    }

    /**
     * The given UUID and subId for the client, everything else kept (the row is replaced with the full client, as every
     * update). Only for clients that authenticate by UUID (VLESS / VMess): a client with a password (Trojan,
     * Shadowsocks) is refused, nothing changed. The answer is read back from the panel before it is trusted; links that
     * cannot be fetched are left empty (the bot builds them, ServiceConfigRegenerator).
     */
    public function rotateCredentials(string $remoteId, string $newUuid, string $newSubId, ?VpnPanel $panel = null): RotatedCredentials
    {
        $panel = $this->requirePanel($panel);
        $ref = $this->ref($remoteId);
        if (!ClientUuid::isUuid($newUuid) || 1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $newSubId)) {
            throw new \InvalidArgumentException('New credentials must be a UUID and a plain subscription id.');
        }
        $current = $this->api->getClient($panel, $ref->email);
        if (null === $current) {
            throw new \RuntimeException(sprintf('Client "%s" does not exist on the panel.', $ref->email));
        }
        if ('' !== (string) ($current['password'] ?? '') || !ClientUuid::isUuid((string) ($current['id'] ?? ''))) {
            throw new \RuntimeException(sprintf('Client "%s" does not authenticate by UUID; its credentials are not rotated here.', $ref->email));
        }
        $this->updateAndVerify($panel, $ref, ['id' => $newUuid, 'subId' => $newSubId]);

        return $this->credentials($panel, $ref, $newUuid, $newSubId);
    }

    public function currentCredentials(string $remoteId, ?VpnPanel $panel = null): ?RotatedCredentials
    {
        $panel = $this->requirePanel($panel);
        $ref = $this->ref($remoteId);
        $current = $this->api->getClient($panel, $ref->email);
        if (null === $current) {
            return null;
        }

        return $this->credentials($panel, $ref, (string) ($current['id'] ?? ''), (string) ($current['subId'] ?? ''));
    }

    private function credentials(VpnPanel $panel, Sanaei3xuiRemoteClientRef $ref, string $uuid, string $subId): RotatedCredentials
    {
        return new RotatedCredentials(
            $this->remoteIdParser->format($ref->panelId ?? $panel->getId(), $ref->localInboundId, $ref->inboundId, $uuid, $ref->email, '' === $subId ? null : $subId),
            $uuid,
            $subId,
            '' === $subId ? null : SubscriptionUrl::build($panel, $subId),
            $this->fetchLinks($panel, $ref->email, '' === $subId ? null : $subId),
        );
    }

    public function deleteService(string $remoteId, ?VpnPanel $panel = null): void
    {
        $panel = $this->requirePanel($panel);
        $email = $this->ref($remoteId)->email;
        $this->api->deleteClient($panel, $email, false);
        if (null !== $this->api->getClient($panel, $email)) {
            throw new \RuntimeException(sprintf('The panel still has client "%s" after deleting it.', $email));
        }
    }

    public function resetUsage(string $remoteId, ?VpnPanel $panel = null): void
    {
        $panel = $this->requirePanel($panel);
        $email = $this->ref($remoteId)->email;
        if (null === $this->api->getClient($panel, $email)) {
            throw new \RuntimeException(sprintf('Client "%s" does not exist on the panel.', $email));
        }
        $this->api->resetTraffic($panel, $email);
    }

    public function getUsage(string $remoteId, ?VpnPanel $panel = null): VpnUsage
    {
        $panel = $this->requirePanel($panel);
        $email = $this->ref($remoteId)->email;
        $traffic = $this->api->traffic($panel, $email);
        if (null === $traffic) {
            throw new \RuntimeException(sprintf('Client "%s" does not exist on the panel.', $email));
        }

        return self::usage(
            usedBytes: isset($traffic['up']) || isset($traffic['down']) ? max(0, (int) ($traffic['up'] ?? 0) + (int) ($traffic['down'] ?? 0)) : null,
            totalBytes: isset($traffic['total']) ? (int) $traffic['total'] : (isset($traffic['totalGB']) ? (int) $traffic['totalGB'] : null),
            expiryMs: isset($traffic['expiryTime']) ? (int) $traffic['expiryTime'] : null,
            enabled: isset($traffic['enable']) ? (bool) $traffic['enable'] : null,
        );
    }

    /**
     * One GET panel/api/clients/list for all requested clients (it carries traffic.up/down; list/paged does not).
     */
    public function getUsages(VpnPanel $panel, array $remoteIds): array
    {
        $panel = $this->requirePanel($panel);
        $clients = $this->api->listClients($panel);
        $usages = [];
        foreach ($remoteIds as $remoteId) {
            $ref = $this->remoteIdParser->parse($remoteId);
            $client = null === $ref ? null : ($clients[strtolower($ref->email)] ?? null);
            if (null === $client) {
                continue;
            }
            $traffic = is_array($client['traffic'] ?? null) ? $client['traffic'] : [];
            $usages[$remoteId] = self::usage(
                usedBytes: ModernClientsApi::usedBytes($client),
                totalBytes: isset($client['totalGB']) ? (int) $client['totalGB'] : null,
                expiryMs: isset($client['expiryTime']) ? (int) $client['expiryTime'] : null,
                enabled: isset($client['enable']) ? (bool) $client['enable'] : (isset($traffic['enable']) ? (bool) $traffic['enable'] : null),
            );
        }

        return $usages;
    }

    /**
     * The client's links as its subscription renders them: subLinks/{subId} (Hosts applied, in the subscription's
     * order), or links/{email} for a client without a subId. Never both: the two can differ (random SNI or CIDR picks),
     * and their union would hand out duplicates (docs/design/multi-inbound-plans.md, 9.1). Empty when the panel cannot
     * answer: at creation and rotation the links are a convenience, the subscription URL works without them.
     *
     * @return list<string>
     */
    public function fetchLinks(VpnPanel $panel, string $email, ?string $subId): array
    {
        try {
            return $this->linksOf($panel, $email, $subId)->links;
        } catch (PanelApiException $e) {
            $this->logger->warning('sanaei_modern_links_unavailable', ['panel_id' => $panel->getId(), 'failure' => $e->failure->value]);

            return [];
        }
    }

    public function panelLinks(string $remoteId, ?string $subId, ?VpnPanel $panel = null, bool $recordHealth = true): PanelLinks
    {
        return $this->linksOf($this->requirePanel($panel), $this->ref($remoteId)->email, $subId, $recordHealth);
    }

    private function linksOf(VpnPanel $panel, string $email, ?string $subId, bool $recordHealth = true): PanelLinks
    {
        $subId = null === $subId || '' === trim($subId) ? null : trim($subId);
        $answer = null === $subId ? $this->api->links($panel, $email, $recordHealth) : $this->api->subLinks($panel, $subId, $recordHealth);
        if ($answer['rejected'] > 0) {
            // Counted per scheme only (e.g. {"socks": 1, "not_a_string": 2}): never the item, it may carry a credential.
            $this->logger->warning('sanaei_modern_links_rejected', ['panel_id' => $panel->getId(), 'rejected' => $answer['rejected'], 'schemes' => $answer['rejectedSchemes']]);
        }

        return new PanelLinks($answer['links'], $answer['rejected'], null === $subId ? 'links' : 'subLinks', null === $subId ? null : SubscriptionUrl::build($panel, $subId));
    }

    /**
     * GET, merge $changes into the full row, POST update, GET again and check the changed values and the preserved
     * identity fields (subId, id) are what was sent.
     *
     * @param array<string, mixed> $changes
     */
    private function updateAndVerify(VpnPanel $panel, Sanaei3xuiRemoteClientRef $ref, array $changes): void
    {
        $current = $this->api->getClient($panel, $ref->email);
        if (null === $current) {
            throw new \RuntimeException(sprintf('Client "%s" does not exist on the panel.', $ref->email));
        }
        $body = ModernClientsApi::mergeForUpdate($current, $changes);
        if (!ClientUuid::isUuid((string) ($body['id'] ?? ''))) {
            // Without the UUID (or with the panel's row id where the UUID belongs) a row replacement would give the
            // client other credentials: refuse, nothing is sent.
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, sprintf('The panel did not return a UUID for client "%s"; refusing to replace the row.', $ref->email));
        }
        if (!array_key_exists('id', $changes) && '' !== $ref->clientId && ClientUuid::isUuid($ref->clientId) && 0 !== strcasecmp($ref->clientId, (string) $body['id'])) {
            // The caller's copy is older than the panel (e.g. the owner just reset the links): the panel's UUID is the
            // one written back (merged from the row read just now), never the caller's. Logged: a write that read the
            // row before such a change and writes after it would put the old one back (ServiceOwnership checks after a
            // reset and puts its credentials back).
            $this->logger->warning('sanaei_modern_client_uuid_differs', ['panel_id' => $panel->getId(), 'remote_inbound_id' => $ref->inboundId]);
        }
        $this->api->updateClient($panel, $ref->email, $body);

        $after = $this->api->getClient($panel, $ref->email);
        if (null === $after) {
            throw new \RuntimeException(sprintf('Client "%s" is missing after the update.', $ref->email));
        }
        foreach ($changes + ['id' => $body['id'], 'subId' => $body['subId'] ?? null] as $field => $expected) {
            if (null !== $expected && array_key_exists($field, $after) && $after[$field] != $expected) {
                throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, sprintf('The panel did not apply "%s" to client "%s".', $field, $ref->email));
            }
        }
    }

    /**
     * @param array<string, mixed> $client normalised client from the panel
     */
    private function createdService(VpnPanel $panel, VpnInbound $inbound, string $remoteInboundId, array $client): CreatedVpnService
    {
        $email = (string) $client['email'];
        $uuid = (string) ($client['id'] ?? '');
        $subId = '' === (string) ($client['subId'] ?? '') ? null : (string) $client['subId'];
        $links = $this->fetchLinks($panel, $email, $subId);
        $limitIp = isset($client['limitIp']) ? (int) $client['limitIp'] : null;

        return new CreatedVpnService(
            remoteId: $this->remoteIdParser->format($panel->getId(), $inbound->getId(), $remoteInboundId, $uuid, $email, $subId),
            username: $email,
            subscriptionUrl: null === $subId ? null : SubscriptionUrl::build($panel, $subId),
            configText: [] === $links ? null : implode("\n", $links),
            clientUuid: $uuid,
            clientEmail: $email,
            subId: $subId,
            ipLimit: 0 === $limitIp ? null : $limitIp,
            configLinks: $links,
        );
    }

    /**
     * @return array{VpnInbound, int}
     */
    private function requireInbound(CreateVpnServiceRequest $request, VpnPanel $panel): array
    {
        $inbound = $request->inbound;
        if (!$inbound instanceof VpnInbound) {
            throw new \RuntimeException('Sanaei provisioning requires a selected inbound.');
        }
        if ($inbound->getPanel()->getId() !== $panel->getId()) {
            throw new \RuntimeException('Selected inbound does not belong to selected panel.');
        }
        $raw = trim((string) ($request->remoteInboundId ?? $inbound->getRemoteInboundId()));
        if (!ctype_digit($raw) || (int) $raw <= 0) {
            throw new \RuntimeException(sprintf('Invalid remote inbound id "%s".', $raw));
        }

        return [$inbound, (int) $raw];
    }

    private function requirePanel(?VpnPanel $panel): VpnPanel
    {
        if (!$this->supports($panel)) {
            throw new \RuntimeException('The modern Sanaei driver needs a sanaei_3xui panel in modern mode.');
        }
        \assert($panel instanceof VpnPanel);

        return $panel;
    }

    private function ref(string $remoteId): Sanaei3xuiRemoteClientRef
    {
        $ref = $this->remoteIdParser->parse($remoteId);
        if (null === $ref) {
            throw new \RuntimeException('Invalid Sanaei remote id.');
        }

        return $ref;
    }

    private function flowFor(VpnInbound $inbound): string
    {
        $flow = trim((string) $inbound->getFlow());

        return 'vless' === strtolower((string) $inbound->getProtocol()) && '' !== $flow && 'none' !== $flow ? $flow : '';
    }

    public static function gbToBytes(?int $gb): int
    {
        return null === $gb || $gb <= 0 ? 0 : $gb * self::BYTES_PER_GB;
    }

    /**
     * 0 days = unlimited (0); otherwise now + days in Unix ms, or minus the duration when it starts at first use.
     */
    public static function expiryMs(int $durationDays, bool $startOnFirstUse = false): int
    {
        if ($durationDays <= 0) {
            return 0;
        }

        return $startOnFirstUse ? -$durationDays * self::MS_PER_DAY : ((new \DateTimeImmutable())->getTimestamp() + $durationDays * 86400) * 1000;
    }

    private static function msToDate(int $ms): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp(intdiv($ms, 1000));
    }

    /**
     * expiryMs: >0 a date, 0 unlimited, <0 a duration not started yet (no date until first use).
     */
    private static function usage(?int $usedBytes, ?int $totalBytes, ?int $expiryMs, ?bool $enabled): VpnUsage
    {
        $totalBytes = null !== $totalBytes && $totalBytes > 0 ? $totalBytes : null;

        return new VpnUsage(
            trafficUsedGb: null === $usedBytes ? null : intdiv($usedBytes, self::BYTES_PER_GB),
            trafficLimitGb: null === $totalBytes ? null : (int) round($totalBytes / self::BYTES_PER_GB),
            usedBytes: $usedBytes,
            totalBytes: $totalBytes,
            expiresAt: null !== $expiryMs && $expiryMs > 0 ? self::msToDate($expiryMs) : null,
            isEnabled: $enabled,
        );
    }
}
