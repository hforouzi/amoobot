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
use App\Provisioning\Domain\ExistingClientAwareDriverInterface;
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
final class Sanaei3xuiModernDriver implements VpnPanelDriverInterface, ExistingClientAwareDriverInterface, TrafficLimitDriverInterface, ServiceActivationDriverInterface, BatchUsageDriverInterface
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
     * Share links (links/{email}) and the subscription's links (subLinks/{subId}) of a client.
     *
     * @return list<string>
     */
    public function fetchLinks(VpnPanel $panel, string $email, ?string $subId): array
    {
        $links = [];
        try {
            $links = $this->api->links($panel, $email);
            if (null !== $subId && '' !== $subId) {
                $links = array_values(array_unique([...$links, ...$this->api->subLinks($panel, $subId)]));
            }
        } catch (PanelApiException $e) {
            // Links are a convenience: the bot can build them itself (VpnAccessLinkGenerator) when the panel cannot.
            $this->logger->warning('sanaei_modern_links_unavailable', ['panel_id' => $panel->getId(), 'failure' => $e->failure->value]);
        }

        return $links;
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
        if ('' === (string) ($body['id'] ?? '')) {
            // Without the UUID a row replacement could give the client new credentials: refuse.
            throw new PanelApiException(PanelFailure::UNEXPECTED_RESPONSE, sprintf('The panel did not return the UUID of client "%s"; refusing to replace the row.', $ref->email));
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
