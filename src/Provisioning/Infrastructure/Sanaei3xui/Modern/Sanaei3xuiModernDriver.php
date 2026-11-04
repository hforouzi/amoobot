<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern;

use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Provisioning\Domain\BatchUsageDriverInterface;
use App\Provisioning\Domain\Dto\AdoptedVpnService;
use App\Provisioning\Domain\Dto\CreatedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\Dto\InboundAttachment;
use App\Provisioning\Domain\Dto\InboundDetachResult;
use App\Provisioning\Domain\Dto\InboundMoveAttachResult;
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
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\InboundDetachDriverInterface;
use App\Provisioning\Domain\InboundMoveDriverInterface;
use App\Provisioning\Domain\PanelLinkSourceDriverInterface;
use App\Provisioning\Domain\ServiceActivationDriverInterface;
use App\Provisioning\Domain\TrafficLimitDriverInterface;
use App\Provisioning\Domain\VpnPanelDriverInterface;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteClientRef;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
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
 * - A plan's additional inbounds (docs/design/multi-inbound-plans.md 3.2) go into the same add; the ones the client is
 *   not on afterwards are attached (only the missing ids), and what is still missing then is reported per inbound, not
 *   thrown: the primary alone makes a working service. Without additional inbounds the requests are exactly the
 *   single-inbound ones.
 */
final class Sanaei3xuiModernDriver implements VpnPanelDriverInterface, ExistingClientAwareDriverInterface, TrafficLimitDriverInterface, ServiceActivationDriverInterface, BatchUsageDriverInterface, ClientOwnerDriverInterface, CredentialRotationDriverInterface, PanelLinkSourceDriverInterface, InboundAttachDriverInterface, InboundDetachDriverInterface, InboundMoveDriverInterface
{
    public const BYTES_PER_GB = 1073741824;
    /**
     * The additional inbounds are never attached at the sale (M2d B1): the sale runs under the settlement's row locks, so
     * there the client is added once with every inbound id and read back (what delivers the primary, as before); the
     * inbounds that read lists are attached, the others are reported pending (AFTER_SALE) and attached right after the
     * commit (ServiceInboundAttacher::afterSale(), no lock held) or by the scheduled run. Within a time-limited pass
     * (attachInbounds() with a deadline) at most MAX_SINGLE_ATTACHES_IN_A_PASS one-by-one calls follow a refused batch.
     */
    public const MAX_SINGLE_ATTACHES_IN_A_PASS = 3;
    private const MS_PER_DAY = 86400000;

    public function __construct(
        private readonly ModernClientsApi $api,
        private readonly Sanaei3xuiRemoteIdParser $remoteIdParser,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new NativeClock(),
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
        $additional = $this->additionalInbounds($request->additionalInbounds, $panel, $remoteInboundId);
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
            $this->api->addClient($panel, $client, [$remoteInboundId, ...array_keys($additional)]);
        } catch (PanelApiException $e) {
            // The add may have been applied and only its answer lost (timeout), or a previous attempt created it.
            // Adopt only a client that carries this order's UUID; anything else stays an error.
            if (PanelFailure::NOT_CONFIGURED === $e->failure || $e->failure->isUnreachable() && PanelFailure::NETWORK_TIMEOUT !== $e->failure) {
                throw $e;
            }
            $existing = $this->api->getClient($panel, $email);
            if (null === $existing) {
                if ([] === $additional || PanelFailure::PANEL_REJECTED !== $e->failure) {
                    throw $e;
                }
                // The panel refused the whole add: one additional inbound may be the reason (e.g. deleted there). The
                // primary alone makes the service; the others are attached below, one by one if need be (D2).
                $this->logger->warning('sanaei_modern_create_retried_with_primary_only', ['panel_id' => $panel->getId(), 'failure' => $e->failure->value]);
                $this->api->addClient($panel, $client, [$remoteInboundId]);
            } elseif (0 !== strcasecmp((string) ($existing['id'] ?? ''), $uuid)) {
                throw new \RuntimeException(sprintf('A client named "%s" already exists on the panel with another UUID; resolve it on the panel, then retry.', $email), 0);
            } else {
                $this->logger->warning('sanaei_modern_create_adopted_after_failure', ['panel_id' => $panel->getId(), 'email' => SecretRedactor::maskClientEmail($email), 'failure' => $e->failure->value]);
            }
        }

        $created = $this->api->getClient($panel, $email);
        if (null === $created || 0 !== strcasecmp((string) ($created['id'] ?? ''), $uuid)) {
            throw new \RuntimeException(sprintf('The panel did not confirm client "%s" after adding it.', $email));
        }
        if (is_array($created['inboundIds'] ?? null) && !in_array($remoteInboundId, $created['inboundIds'], true)) {
            $this->api->attach($panel, $email, [$remoteInboundId]);
        }

        // Additional inbounds: what the read lists is attached, the rest waits for the pass after the commit (B1).
        return $this->createdService($panel, $inbound, (string) $remoteInboundId, $created, [] === $additional ? [] : self::afterSale($created, $additional));
    }

    public function findExistingService(CreateVpnServiceRequest $request, ?VpnPanel $panel = null): ?AdoptedVpnService
    {
        $panel = $this->requirePanel($panel);
        [$inbound, $remoteInboundId] = $this->requireInbound($request, $panel);
        $additional = $this->additionalInbounds($request->additionalInbounds, $panel, $remoteInboundId);
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
        $this->logger->warning('sanaei_modern_existing_client_found', ['panel_id' => $panel->getId(), 'email' => SecretRedactor::maskClientEmail((string) ($client['email'] ?? $email)), 'matched_by' => $matchedBy]);

        $expiryMs = (int) ($client['expiryTime'] ?? 0);
        $totalBytes = (int) ($client['totalGB'] ?? 0);
        // Only a client that belongs to this order may be attached to the plan's inbounds here; an email match on a
        // customer-chosen name is refused by the caller (VpnProvisioningService) before anything is changed.
        $attachments = [];
        if (AdoptedVpnService::MATCHED_BY_UUID === $matchedBy && is_array($client['inboundIds'] ?? null) && !in_array($remoteInboundId, $client['inboundIds'], true)) {
            $this->api->attach($panel, (string) $client['email'], [$remoteInboundId]);
        }
        if ([] !== $additional && AdoptedVpnService::MATCHED_BY_UUID === $matchedBy) {
            // As at creation (B1): nothing more under the settlement's locks, the rest is attached after the commit.
            $attachments = self::afterSale($client, $additional);
        } elseif ([] !== $additional) {
            $attachments = self::attachments($client, $additional, [], 'not attached: the client was adopted by its name only (use "Retry attach")');
        }

        return new AdoptedVpnService(
            service: $this->createdService($panel, $inbound, (string) $remoteInboundId, $client, $attachments),
            matchedBy: $matchedBy,
            expiresAt: $expiryMs > 0 ? self::msToDate($expiryMs) : null,
            trafficLimitGb: $totalBytes > 0 ? (int) round($totalBytes / self::BYTES_PER_GB) : null,
        );
    }

    public function attachInbounds(string $remoteId, array $inbounds, ?VpnPanel $panel = null, bool $recordHealth = true, ?float $deadline = null): array
    {
        $panel = $this->requirePanel($panel);
        $ref = $this->ref($remoteId);
        $client = $this->api->getClient($panel, $ref->email, $recordHealth);
        if (null === $client) {
            throw new \RuntimeException(sprintf('Client "%s" does not exist on the panel.', $ref->email));
        }

        return $this->attachMissing($panel, $client, null, $this->additionalInbounds($inbounds, $panel, null), $recordHealth, $deadline)[1];
    }

    public function clientInboundIds(string $remoteId, ?VpnPanel $panel = null, bool $recordHealth = true): ?array
    {
        $panel = $this->requirePanel($panel);
        $client = $this->api->getClient($panel, $this->ref($remoteId)->email, $recordHealth);
        if (null === $client) {
            return null;
        }
        if (!isset($client['inboundIds']) || !is_array($client['inboundIds'])) {
            throw new \RuntimeException('The panel did not list the client\'s inbounds.');
        }

        return array_values(array_map('intval', $client['inboundIds']));
    }

    /**
     * docs/design/plan-inbound-change.md 4.3 step 5 and 4.4 (P2: not called by anything yet). In this order:
     * 1. without any request: refuse a panel that is not modern, a remote id that cannot be read or names another panel,
     *    an empty list, an inbound of another panel, and any inbound that is the service's primary (local or as the
     *    remote id records it);
     * 2. GET the client. Unreadable, missing, without an inbound list or a UUID, or another client (UUID): nothing is sent;
     * 3. on that read: leaving no inbound at all is refused; a client not on its primary (a hand edit) is failed; none of
     *    the inbounds listed is "already detached" (nothing sent);
     * 4. one detach call with the listed ids only, then a GET: done only when that read lists none of them and still the
     *    primary. A lost or garbled answer is read back the same way; a refused or unreachable call is failed.
     */
    public function detachInbounds(string $remoteId, ?VpnInbound $localPrimary, array $inbounds, ?VpnPanel $panel = null, bool $recordHealth = true): InboundDetachResult
    {
        if (!$this->supports($panel)) {
            return InboundDetachResult::refused(InboundDetachResult::REFUSED_NOT_MODERN, InboundAttachDriverInterface::MODERN_PANEL_REQUIRED);
        }
        \assert($panel instanceof VpnPanel);
        $ref = $this->remoteIdParser->parse($remoteId);
        // panel=0 in a remote id means "not known" (Sanaei3xuiRemoteIdParser::format()).
        if (null === $ref || !ctype_digit(trim($ref->inboundId)) || ((int) $ref->panelId > 0 && null !== $panel->getId() && $ref->panelId !== $panel->getId())) {
            return InboundDetachResult::refused(InboundDetachResult::REFUSED_INVALID_SERVICE, 'The service\'s remote id cannot be read or names another panel: nothing sent.');
        }
        $primaryRemoteId = (int) trim($ref->inboundId);
        $requested = $this->detachTargets($inbounds, $panel);
        if (is_string($requested)) {
            return InboundDetachResult::refused(InboundDetachResult::REFUSED_INVALID_INBOUND, $requested);
        }
        foreach ($requested as $remote => $inbound) {
            if ($remote === $primaryRemoteId || self::isLocalPrimary($inbound, $localPrimary, $ref->localInboundId, $panel)) {
                return InboundDetachResult::refused(InboundDetachResult::REFUSED_PRIMARY, sprintf('Inbound %d is the service\'s primary inbound: it is never detached (nothing sent).', $remote));
            }
        }
        $ids = array_keys($requested);

        // Never a detach before a read confirms the client's current inbounds (3x-ui has no guard of its own).
        $before = $this->readForDetach($panel, $ref->email, $ref->clientId, $recordHealth, false);
        if ($before instanceof InboundDetachResult) {
            return $before;
        }
        $listed = $before['inboundIds'];
        $toDetach = array_values(array_intersect($ids, $listed));
        $already = array_values(array_diff($ids, $listed));
        if ([] !== $toDetach && [] === array_diff($listed, $toDetach)) {
            return InboundDetachResult::refused(InboundDetachResult::REFUSED_LAST_INBOUND, 'The client would be left on no inbound at all: nothing sent.', $listed);
        }
        if (!in_array($primaryRemoteId, $listed, true)) {
            return InboundDetachResult::failed(InboundDetachResult::FAILED_NOT_ON_PRIMARY, sprintf('The client is not on the service\'s primary inbound %d on the panel (changed by hand?): nothing sent.', $primaryRemoteId), $listed);
        }
        if ([] === $toDetach) {
            return InboundDetachResult::alreadyDetached($already, $listed);
        }

        $sendError = null;
        try {
            $this->api->detach($panel, $ref->email, $toDetach, $recordHealth);
        } catch (PanelApiException $e) {
            $sendError = $e;
            if (self::detachNotApplied($e)) {
                $this->logDetach('sanaei_modern_detach_failed', $panel, InboundDetachResult::FAILED_PANEL, $e);

                return InboundDetachResult::failed(InboundDetachResult::FAILED_PANEL, self::safeError($e, 'The panel did not detach the inbound'), $listed);
            }
            // A timeout, an HTTP error or a garbled answer: the panel may have applied it. Only a read decides.
        }

        $after = $this->readForDetach($panel, $ref->email, $ref->clientId, $recordHealth, true, $sendError);
        if ($after instanceof InboundDetachResult) {
            return $after;
        }
        $stillListed = array_values(array_intersect($toDetach, $after['inboundIds']));
        if ([] !== $stillListed || !in_array($primaryRemoteId, $after['inboundIds'], true)) {
            $this->logDetach('sanaei_modern_detach_not_applied', $panel, InboundDetachResult::FAILED_STILL_LISTED, $sendError);

            return InboundDetachResult::failed(
                InboundDetachResult::FAILED_STILL_LISTED,
                [] !== $stillListed
                    ? sprintf('The panel still lists inbound(s) %s after the detach%s', implode(', ', $stillListed), null === $sendError ? '.' : ': '.self::safeError($sendError, 'the call failed'))
                    : sprintf('The client is no longer on the primary inbound %d after the detach: check it on the panel.', $primaryRemoteId),
                $after['inboundIds'],
            );
        }
        if (null !== $sendError) {
            $this->logger->warning('sanaei_modern_detach_confirmed_after_lost_answer', ['panel_id' => $panel->getId(), 'failure' => $sendError->failure->value]);
        }

        return InboundDetachResult::done($toDetach, $already, $after['inboundIds']);
    }

    /**
     * docs/design/plan-inbound-change.md 4.3 step 2 (P4): put the client on the target B before the service is switched.
     * In this order:
     * 1. without any request: refuse a panel that is not modern, a remote id that cannot be read, has no numeric inbound
     *    or names another panel, and a target of another panel or with an invalid remote id;
     * 2. GET the client. Unreadable, missing, without an inbound list or a UUID, or another client (UUID): nothing sent;
     * 3. B listed: done, nothing sent. On neither A (the inbound the remote id records) nor B: failed, nothing sent;
     * 4. one attach call with B only, then a GET: done only when that read lists B. A lost or garbled answer is read
     *    back the same way; a refused call (nothing applied) is failed without a read.
     */
    public function attachForMove(string $remoteId, VpnInbound $target, ?VpnPanel $panel = null, bool $recordHealth = true): InboundMoveAttachResult
    {
        if (!$this->supports($panel)) {
            return InboundMoveAttachResult::refused(InboundMoveAttachResult::REFUSED_NOT_MODERN, InboundAttachDriverInterface::MODERN_PANEL_REQUIRED);
        }
        \assert($panel instanceof VpnPanel);
        $ref = $this->remoteIdParser->parse($remoteId);
        if (null === $ref || !ctype_digit(trim($ref->inboundId)) || ((int) $ref->panelId > 0 && null !== $panel->getId() && $ref->panelId !== $panel->getId())) {
            return InboundMoveAttachResult::refused(InboundMoveAttachResult::REFUSED_INVALID_SERVICE, 'The service\'s remote id cannot be read or names another panel: nothing sent.');
        }
        $targetPanel = $target->getPanel();
        $rawTarget = trim($target->getRemoteInboundId());
        if (($targetPanel !== $panel && (null === $targetPanel->getId() || $targetPanel->getId() !== $panel->getId())) || !ctype_digit($rawTarget) || (int) $rawTarget <= 0) {
            return InboundMoveAttachResult::refused(InboundMoveAttachResult::REFUSED_INVALID_TARGET, 'The target inbound is on another panel or has an invalid remote inbound id: nothing sent.');
        }
        $from = (int) trim($ref->inboundId);
        $to = (int) $rawTarget;

        $before = $this->readForMove($panel, $ref->email, $ref->clientId, $recordHealth, false);
        if ($before instanceof InboundMoveAttachResult) {
            return $before;
        }
        if (in_array($to, $before, true)) {
            return InboundMoveAttachResult::alreadyOnTarget($before);
        }
        if (!in_array($from, $before, true)) {
            $this->logger->warning('sanaei_modern_move_not_on_expected', ['panel_id' => $panel->getId()]);

            return InboundMoveAttachResult::failed(InboundMoveAttachResult::FAILED_NOT_ON_EXPECTED, 'Not moved: '.InboundMoveAttachResult::NOT_ON_EXPECTED_TEXT, $before);
        }

        $sendError = null;
        try {
            $this->api->attach($panel, $ref->email, [$to], $recordHealth);
        } catch (PanelApiException $e) {
            $sendError = $e;
            if (self::detachNotApplied($e)) {
                // The same "certainly not applied" kinds as for a detach: refused, auth, wrong URL, not reached.
                $this->logger->warning('sanaei_modern_move_attach_failed', ['panel_id' => $panel->getId(), 'failure' => $e->failure->value]);

                return InboundMoveAttachResult::failed(InboundMoveAttachResult::FAILED_NOT_ATTACHED, self::moveError($e, 'The panel did not attach the new inbound', $ref->email), $before);
            }
        }

        $after = $this->readForMove($panel, $ref->email, $ref->clientId, $recordHealth, true);
        if ($after instanceof InboundMoveAttachResult) {
            return $after;
        }
        if (!in_array($to, $after, true)) {
            $this->logger->warning('sanaei_modern_move_attach_not_applied', ['panel_id' => $panel->getId(), 'failure' => $sendError?->failure->value]);

            return InboundMoveAttachResult::failed(InboundMoveAttachResult::FAILED_NOT_ATTACHED, sprintf('The panel does not list inbound %d after the attach%s', $to, null === $sendError ? '.' : ': '.self::moveError($sendError, 'the call failed', $ref->email)), $after);
        }

        return InboundMoveAttachResult::attached($after);
    }

    /**
     * GET the client for a move: its inbound ids, or the failure (a partial answer is never trusted; another UUID under
     * the same email is not this service's client). After the attach, every failure means "not confirmed".
     *
     * @return list<int>|InboundMoveAttachResult
     */
    private function readForMove(VpnPanel $panel, string $email, string $uuid, bool $recordHealth, bool $afterAttach): array|InboundMoveAttachResult
    {
        $suffix = $afterAttach ? ' after the attach: not confirmed.' : ': nothing sent.';
        $reason = static fn (string $before): string => $afterAttach ? InboundMoveAttachResult::FAILED_NOT_CONFIRMED : $before;
        try {
            $client = $this->api->getClient($panel, $email, $recordHealth);
        } catch (PanelApiException $e) {
            $this->logger->warning('sanaei_modern_move_read_failed', ['panel_id' => $panel->getId(), 'after_attach' => $afterAttach, 'failure' => $e->failure->value]);

            return InboundMoveAttachResult::failed($reason(InboundMoveAttachResult::FAILED_UNREADABLE), self::moveError($e, 'The client could not be read', $email).$suffix, null, true);
        }
        if (null === $client) {
            return InboundMoveAttachResult::failed($reason(InboundMoveAttachResult::FAILED_CLIENT_MISSING), 'The panel has no such client'.$suffix);
        }
        if (!isset($client['inboundIds']) || !is_array($client['inboundIds']) || '' === (string) ($client['id'] ?? '')) {
            return InboundMoveAttachResult::failed($reason(InboundMoveAttachResult::FAILED_NOT_LISTED), 'The panel did not list the client\'s inbounds or UUID'.$suffix);
        }
        $ids = array_values(array_unique(array_map('intval', $client['inboundIds'])));
        if (0 !== strcasecmp((string) $client['id'], $uuid)) {
            return InboundMoveAttachResult::failed($reason(InboundMoveAttachResult::FAILED_NOT_THIS_CLIENT), 'The panel\'s client under this email has another UUID (not this service\'s)'.$suffix, $ids);
        }

        return $ids;
    }

    /** A panel failure as a safe, short text: no access data, and neither the client's email nor its request path. */
    private static function moveError(PanelApiException $e, string $what, string $email): string
    {
        $text = (string) preg_replace('#clients/[^/\s]+/#', 'clients/***/', self::safeError($e, $what));

        return '' === trim($email) ? $text : str_ireplace([$email, rawurlencode($email)], '***', $text);
    }

    /**
     * The inbounds to detach by remote id (repeats dropped), or why the list is refused.
     *
     * @param list<VpnInbound> $inbounds
     *
     * @return array<int, VpnInbound>|string
     */
    private function detachTargets(array $inbounds, VpnPanel $panel): array|string
    {
        if ([] === $inbounds) {
            return 'No inbound to detach was given: nothing sent.';
        }
        $byRemoteId = [];
        foreach ($inbounds as $inbound) {
            $other = $inbound->getPanel();
            if ($other !== $panel && (null === $other->getId() || $other->getId() !== $panel->getId())) {
                return 'An inbound to detach does not belong to the service\'s panel: nothing sent.';
            }
            $raw = trim($inbound->getRemoteInboundId());
            if (!ctype_digit($raw) || (int) $raw <= 0) {
                return 'An inbound to detach has an invalid remote inbound id: nothing sent.';
            }
            $byRemoteId[(int) $raw] ??= $inbound;
        }

        return $byRemoteId;
    }

    /** Whether $inbound is the service's local primary: the same entity or id, or the same remote inbound on its panel. */
    private static function isLocalPrimary(VpnInbound $inbound, ?VpnInbound $localPrimary, ?int $localPrimaryId, VpnPanel $panel): bool
    {
        if (null !== $inbound->getId() && $inbound->getId() === $localPrimaryId) {
            return true;
        }
        if (null === $localPrimary) {
            return false;
        }
        if ($inbound === $localPrimary || (null !== $inbound->getId() && $inbound->getId() === $localPrimary->getId())) {
            return true;
        }
        $primaryPanel = $localPrimary->getPanel();
        $samePanel = $primaryPanel === $panel || (null !== $primaryPanel->getId() && $primaryPanel->getId() === $panel->getId());

        return $samePanel && trim($localPrimary->getRemoteInboundId()) === trim($inbound->getRemoteInboundId());
    }

    /**
     * GET the client for a detach: its inbound list as the panel gives it, or the failure (a partial answer, without the
     * list or the UUID, is never trusted; another UUID under the same email is not this service's client).
     *
     * @return array{inboundIds: list<int>}|InboundDetachResult
     */
    private function readForDetach(VpnPanel $panel, string $email, string $uuid, bool $recordHealth, bool $afterDetach, ?PanelApiException $sendError = null): array|InboundDetachResult
    {
        // Before the detach every failure means "nothing sent"; after it, "not confirmed" (the panel may have applied it).
        $fail = function (string $beforeReason, string $what, ?array $ids = null, ?PanelApiException $e = null) use ($panel, $afterDetach, $sendError): InboundDetachResult {
            $reason = $afterDetach ? InboundDetachResult::FAILED_NOT_CONFIRMED : $beforeReason;
            $this->logDetach($afterDetach ? 'sanaei_modern_detach_not_confirmed' : 'sanaei_modern_detach_read_failed', $panel, $reason, $e ?? $sendError);
            $text = $afterDetach
                ? $what.' after the detach: not confirmed'.(null === $sendError ? '' : ' ('.self::safeError($sendError, 'the detach call').')').'.'
                : $what.': nothing sent.';

            return InboundDetachResult::failed($reason, $text, $ids);
        };
        try {
            $client = $this->api->getClient($panel, $email, $recordHealth);
        } catch (PanelApiException $e) {
            return $fail(InboundDetachResult::FAILED_UNREADABLE, self::safeError($e, 'The client could not be read'), null, $e);
        }
        if (null === $client) {
            return $afterDetach
                ? $fail(InboundDetachResult::FAILED_CLIENT_MISSING, 'The panel has no such client')
                : InboundDetachResult::failed(InboundDetachResult::FAILED_CLIENT_MISSING, 'The panel has no such client: nothing sent.');
        }
        if (!isset($client['inboundIds']) || !is_array($client['inboundIds'])) {
            return $fail(InboundDetachResult::FAILED_NOT_LISTED, 'The panel did not list the client\'s inbounds');
        }
        $id = (string) ($client['id'] ?? '');
        if ('' === $id) {
            return $fail(InboundDetachResult::FAILED_NOT_LISTED, 'The panel did not give the client\'s UUID');
        }
        $ids = array_values(array_unique(array_map('intval', $client['inboundIds'])));
        if (0 !== strcasecmp($id, $uuid)) {
            return $afterDetach
                ? $fail(InboundDetachResult::FAILED_NOT_CONFIRMED, 'The panel\'s client under this email has another UUID', $ids)
                : InboundDetachResult::refused(InboundDetachResult::REFUSED_NOT_THIS_CLIENT, 'The panel\'s client under this email has another UUID (not this service\'s): nothing sent.', $ids);
        }

        return ['inboundIds' => $ids];
    }

    /**
     * A detach call that certainly changed nothing: never sent or not reachable (no answer can be lost), refused by
     * auth or a wrong URL, or refused by the panel (3x-ui v3.11.0 loads every inbound before it writes anything, so
     * success:false means nothing was written: [3x-ui] source, not yet checked on a real panel). A timeout, an HTTP
     * error or a garbled answer may hide an applied detach: read back.
     */
    private static function detachNotApplied(PanelApiException $e): bool
    {
        return PanelFailure::NOT_CONFIGURED === $e->failure
            || PanelFailure::AUTH_REJECTED === $e->failure
            || PanelFailure::WRONG_URL === $e->failure
            || PanelFailure::PANEL_REJECTED === $e->failure
            || ($e->failure->isUnreachable() && PanelFailure::NETWORK_TIMEOUT !== $e->failure);
    }

    /** A panel failure as a safe, short text (PanelApiException messages are redacted already; this removes access data too). */
    private static function safeError(PanelApiException $e, string $what): string
    {
        return SecretRedactor::redactAccess(sprintf('%s: %s: %s', $what, $e->failure->value, $e->getMessage()), 300);
    }

    private function logDetach(string $event, VpnPanel $panel, string $reason, ?PanelApiException $e = null): void
    {
        // Ids and failure kinds only: no email, UUID, subscription id or panel message (they stay in the result's safe text).
        $this->logger->warning($event, ['panel_id' => $panel->getId(), 'reason' => $reason, 'failure' => $e?->failure->value]);
    }

    /**
     * The additional inbounds by their remote id (repeats and the primary dropped).
     *
     * @param list<VpnInbound> $inbounds
     *
     * @return array<int, VpnInbound>
     */
    private function additionalInbounds(array $inbounds, VpnPanel $panel, ?int $primaryRemoteId): array
    {
        $byRemoteId = [];
        foreach ($inbounds as $inbound) {
            $other = $inbound->getPanel();
            if ($other !== $panel && (null === $other->getId() || $other->getId() !== $panel->getId())) {
                throw new \RuntimeException('An additional inbound does not belong to the selected panel.');
            }
            $raw = trim($inbound->getRemoteInboundId());
            if (!ctype_digit($raw) || (int) $raw <= 0) {
                throw new \RuntimeException(sprintf('Invalid remote inbound id "%s".', $raw));
            }
            if ((int) $raw !== $primaryRemoteId) {
                $byRemoteId[(int) $raw] ??= $inbound;
            }
        }

        return $byRemoteId;
    }

    /**
     * GET was done ($client): attaches the inbounds the client is not on (one call with the missing ids only; one by one
     * when the panel refuses the batch, so one bad inbound does not keep the others off), then GETs again. The primary,
     * when given, must be attached afterwards (as before: no service without it); the additional ones are reported.
     *
     * @param array<string, mixed>   $client
     * @param array<int, VpnInbound> $additional
     *
     * @return array{array<string, mixed>, list<InboundAttachment>} the client as read last, one result per additional inbound
     */
    private function attachMissing(VpnPanel $panel, array $client, ?int $primaryRemoteId, array $additional, bool $recordHealth = true, ?float $deadline = null): array
    {
        $email = (string) $client['email'];
        $wanted = [...(null === $primaryRemoteId ? [] : [$primaryRemoteId]), ...array_keys($additional)];
        // A client whose answer lists no inbounds: attach all of them (attaching an attached one is harmless).
        $missing = is_array($client['inboundIds'] ?? null) ? array_values(array_diff($wanted, $client['inboundIds'])) : $wanted;
        $errors = [];
        if ([] !== $missing) {
            $errors = $this->attachIds($panel, $email, $missing, $recordHealth, $deadline);
            $primaryAttached = null === $primaryRemoteId || !in_array($primaryRemoteId, $missing, true);
            try {
                // Out of time (F5) and the primary already confirmed: not read back, the rest is pending.
                $read = $primaryAttached && $this->pastDeadline($deadline) ? null : $this->api->getClient($panel, $email, $recordHealth);
            } catch (PanelApiException $e) {
                $read = null;
                $this->logger->warning('sanaei_modern_read_after_attach_failed', ['panel_id' => $panel->getId(), 'failure' => $e->failure->value]);
            }
            if (null === $read) {
                // Not known (M2c F2). The primary as read before the attach decides the service; the additional inbounds
                // that were sent stay pending for the scheduled run, the refused ones failed. A paid order whose primary
                // works is never failed here.
                if (!$primaryAttached) {
                    throw new \RuntimeException(sprintf('The panel could not be read after attaching client "%s" to the plan\'s inbound %d.', $email, $primaryRemoteId));
                }

                return [$client, self::unconfirmed($client, $additional, $errors)];
            }
            $client = $read;
        }
        if (null !== $primaryRemoteId && is_array($client['inboundIds'] ?? null) && !in_array($primaryRemoteId, $client['inboundIds'], true)) {
            throw new \RuntimeException(sprintf('The panel did not attach client "%s" to the plan\'s inbound %d%s', $email, $primaryRemoteId, isset($errors[$primaryRemoteId]) ? ': '.$errors[$primaryRemoteId] : '.'));
        }

        return [$client, self::attachments($client, $additional, $errors, 'the panel did not attach it')];
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string> remote inbound id => why its attach failed (a safe text)
     */
    private function attachIds(VpnPanel $panel, string $email, array $ids, bool $recordHealth = true, ?float $deadline = null): array
    {
        try {
            $this->api->attach($panel, $email, $ids, $recordHealth);

            return [];
        } catch (PanelApiException $e) {
            if (1 === count($ids) || PanelFailure::NOT_CONFIGURED === $e->failure || $e->failure->isUnreachable() && PanelFailure::NETWORK_TIMEOUT !== $e->failure) {
                return array_fill_keys($ids, self::attachError($e));
            }
            $this->logger->warning('sanaei_modern_attach_batch_refused', ['panel_id' => $panel->getId(), 'inbounds' => count($ids), 'failure' => $e->failure->value]);
        }
        $errors = [];
        $singles = 0;
        foreach ($ids as $id) {
            if (null !== $deadline && ($singles >= self::MAX_SINGLE_ATTACHES_IN_A_PASS || $this->pastDeadline($deadline))) {
                $errors[$id] = InboundAttachDriverInterface::NOT_TRIED;
                continue;
            }
            ++$singles;
            try {
                $this->api->attach($panel, $email, [$id], $recordHealth);
            } catch (PanelApiException $e) {
                $errors[$id] = self::attachError($e);
            }
        }

        return $errors;
    }

    private function pastDeadline(?float $deadline): bool
    {
        return null !== $deadline && (float) $this->clock->now()->format('U.u') >= $deadline;
    }

    private static function attachError(PanelApiException $e): string
    {
        // PanelApiException messages are redacted already (no token, URL or base path).
        return mb_substr(sprintf('%s: %s', $e->failure->value, $e->getMessage()), 0, 200);
    }

    /**
     * After an attach whose result could not be read back: on the client as read before = attached; refused by the
     * panel = failed with its reason; sent (or not tried) = pending.
     *
     * @param array<string, mixed>   $client
     * @param array<int, VpnInbound> $additional
     * @param array<int, string>     $errors
     *
     * @return list<InboundAttachment>
     */
    private static function unconfirmed(array $client, array $additional, array $errors): array
    {
        $before = is_array($client['inboundIds'] ?? null) ? $client['inboundIds'] : [];
        $result = [];
        foreach ($additional as $remoteId => $inbound) {
            $result[] = match (true) {
                in_array($remoteId, $before, true) => new InboundAttachment($inbound, true),
                isset($errors[$remoteId]) && InboundAttachDriverInterface::NOT_TRIED !== $errors[$remoteId] => new InboundAttachment($inbound, false, $errors[$remoteId]),
                default => InboundAttachment::pending($inbound, $errors[$remoteId] ?? InboundAttachDriverInterface::NOT_CONFIRMED),
            };
        }

        return $result;
    }

    /**
     * At the sale (B1): an additional inbound the client as read is on = attached; any other = pending (AFTER_SALE, not
     * tried), for the pass after the commit. A read that does not list the inbounds says nothing about them: pending too.
     *
     * @param array<string, mixed>   $client
     * @param array<int, VpnInbound> $additional
     *
     * @return list<InboundAttachment>
     */
    private static function afterSale(array $client, array $additional): array
    {
        $on = is_array($client['inboundIds'] ?? null) ? $client['inboundIds'] : [];
        $result = [];
        foreach ($additional as $remoteId => $inbound) {
            $result[] = in_array($remoteId, $on, true) ? new InboundAttachment($inbound, true) : InboundAttachment::pending($inbound, InboundAttachDriverInterface::AFTER_SALE);
        }

        return $result;
    }

    /**
     * What the panel says about each additional inbound: on the client's inboundIds = attached.
     *
     * @param array<string, mixed>   $client
     * @param array<int, VpnInbound> $additional
     * @param array<int, string>     $errors
     *
     * @return list<InboundAttachment>
     */
    private static function attachments(array $client, array $additional, array $errors, string $notAttached): array
    {
        $listed = isset($client['inboundIds']) && is_array($client['inboundIds']);
        $result = [];
        foreach ($additional as $remoteId => $inbound) {
            $result[] = match (true) {
                !$listed => new InboundAttachment($inbound, false, 'the panel does not list the client\'s inbounds: not confirmed'),
                in_array($remoteId, $client['inboundIds'], true) => new InboundAttachment($inbound, true),
                InboundAttachDriverInterface::NOT_TRIED === ($errors[$remoteId] ?? null) => InboundAttachment::pending($inbound, InboundAttachDriverInterface::NOT_TRIED),
                default => new InboundAttachment($inbound, false, $errors[$remoteId] ?? $notAttached),
            };
        }

        return $result;
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
     * @param array<string, mixed>    $client      normalised client from the panel
     * @param list<InboundAttachment> $attachments
     */
    private function createdService(VpnPanel $panel, VpnInbound $inbound, string $remoteInboundId, array $client, array $attachments = []): CreatedVpnService
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
            attachments: $attachments,
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
