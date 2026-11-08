<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Bot\Application\TelegramUserResolver;
use App\Entity\TelegramAccount;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\ClientUuid;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelApiException;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\ModernClientsApi;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\SubscriptionUrl;
use App\Provisioning\Infrastructure\Sanaei3xui\Sanaei3xuiRemoteIdParser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * app:panel:import-clients (docs/design/panel-client-import.md): turns the clients a modern 3x-ui panel already has into
 * VpnService rows owned by the Telegram user the client's tgId names, so customers whose services exist only on the
 * panel see them in the bot and the Mini App. Read-only towards the panel (GETs only); no order, payment, wallet entry
 * or notification is created. Imported services carry importedAt and no order.
 *
 * Per page of clients (clients/list/paged): every read for the page happens first (the full row of each new client and
 * its links); then the page's users, accounts and services are written in one transaction. A failed read stops the run
 * before anything of that page is written; pages before it stay committed. A second run imports nothing: a client is
 * "already known" when a service has its UUID, its email on the same panel, or its subId.
 */
final class PanelClientImporter
{
    public const DEFAULT_BATCH_SIZE = 100;
    public const SOURCE = 'panel_import';
    private const BYTES_PER_GB = 1073741824;
    /** Telegram user ids are positive and below 2^53 (Bot API). */
    private const MAX_TELEGRAM_ID = 9007199254740991;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ModernClientsApi $api,
        private readonly Sanaei3xuiRemoteIdParser $remoteIds,
        private readonly TelegramUserResolver $users,
        private readonly ServiceNotificationService $notifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param int|null $limit stop once this many services were imported (would be, in a dry run)
     */
    public function import(VpnPanel $panel, bool $dryRun = false, ?int $limit = null, int $batchSize = self::DEFAULT_BATCH_SIZE): PanelClientImportSummary
    {
        $summary = new PanelClientImportSummary($dryRun);
        if ('sanaei_3xui' !== $panel->getType() || !$panel->isModern()) {
            $summary->stopped = PanelClientImportSummary::STOPPED_NOT_MODERN;

            return $summary;
        }
        $panelId = (int) $panel->getId();
        $batchSize = max(1, min(ModernClientsApi::PAGE_SIZE_MAX, $batchSize));
        $known = $this->knownIdentities($panelId);
        /** @var array<string, true> $owners Telegram ids that have an account (or get one in this run) */
        $owners = [];
        /** @var array<string, true> $matched pre-existing accounts that got a service in this run */
        $matched = [];
        /** @var array<string, int> $perOwner services imported in this run, by Telegram id (only counted, never shown) */
        $perOwner = [];

        try {
            // Traffic (up/down) is only on clients/list: one request for the whole panel.
            $usage = array_map(static fn (array $client): ?int => ModernClientsApi::usedBytes($client), $this->api->listClients($panel));
            for ($page = 1; ; ++$page) {
                $answer = $this->api->listPaged($panel, $page, $batchSize);
                $planned = [];
                foreach ($answer['items'] as $item) {
                    if (null !== $limit && $summary->imported + count($planned) >= $limit) {
                        $summary->limitReached = true;
                        break;
                    }
                    ++$summary->read;
                    $import = $this->readClient($panel, $item, $known, $usage, $summary);
                    if (null !== $import) {
                        $planned[] = $import;
                        $known->add($import['uuid'], $import['email'], $import['subId'], $panelId);
                    }
                }
                $this->countOwners($planned, $owners, $matched, $summary);
                foreach ($planned as $import) {
                    $perOwner[$import['telegramId']] = ($perOwner[$import['telegramId']] ?? 0) + 1;
                }
                $summary->mostForOneOwner = [] === $perOwner ? 0 : max($perOwner);
                if (!$dryRun && [] !== $planned) {
                    $panel = $this->write($panelId, $planned);
                }
                $summary->imported += count($planned);
                if ($summary->limitReached || [] === $answer['items'] || $page * $batchSize >= $answer['total']) {
                    break;
                }
            }
        } catch (PanelApiException $e) {
            $summary->stopped = PanelClientImportSummary::STOPPED_PANEL_READ;
            $summary->failure = $e->failure->value;
            $this->logger->warning('panel_client_import_stopped', ['panel_id' => $panelId, 'failure' => $e->failure->value, 'imported' => $summary->imported]);
        }

        $this->logger->info('panel_client_import', ['panel_id' => $panelId, 'dry_run' => $dryRun, 'read' => $summary->read, 'imported' => $summary->imported,
            'new_users' => $summary->newUsers, 'existing_users' => $summary->existingUsers, 'already_known' => $summary->alreadyKnown, 'no_owner' => $summary->noOwner,
            'unknown_inbound' => $summary->unknownInbound, 'invalid' => $summary->invalid, 'most_for_one_owner' => $summary->mostForOneOwner, 'stopped' => $summary->stopped]);

        return $summary;
    }

    /**
     * Classifies one client of a page and, for a new one with an owner, reads what the service needs from the panel.
     *
     * @param array<string, mixed>   $item  a row of clients/list/paged (normalised)
     * @param array<string, ?int>    $usage used bytes by lower-cased email
     *
     * @return array{uuid: string, email: string, subId: ?string, telegramId: string, inbounds: list<VpnInbound>, remoteInboundId: int, client: array<string, mixed>, usedBytes: ?int, links: ?list<string>}|null
     *
     * @throws PanelApiException
     */
    private function readClient(VpnPanel $panel, array $item, PanelClientIdentities $known, array $usage, PanelClientImportSummary $summary): ?array
    {
        $panelId = (int) $panel->getId();
        $email = trim((string) ($item['email'] ?? ''));
        if ('' === $email) {
            ++$summary->invalid;

            return null;
        }
        if ($known->has(self::uuidOf($item), $email, self::subIdOf($item), $panelId)) {
            ++$summary->alreadyKnown;

            return null;
        }
        // The paged rows leave out fields (tgId, the UUID, limitIp): the full row has them.
        $client = $this->api->getClient($panel, $email, false, true);
        if (null === $client || 0 !== strcasecmp(trim((string) ($client['email'] ?? '')), $email)) {
            ++$summary->invalid;

            return null;
        }
        $client += $item;
        $uuid = self::uuidOf($client);
        $subId = self::subIdOf($client);
        if ($known->has($uuid, $email, $subId, $panelId)) {
            ++$summary->alreadyKnown;

            return null;
        }
        if (array_key_exists('tgId', $client)) {
            $summary->tgIdReported = true;
        }
        $telegramId = self::telegramIdOf($client['tgId'] ?? null);
        if (null === $telegramId) {
            ++$summary->noOwner;

            return null;
        }
        $remoteInboundIds = is_array($client['inboundIds'] ?? null) ? array_values(array_unique(array_filter($client['inboundIds'], static fn (int $id): bool => $id > 0))) : [];
        if (null === $uuid || [] === $remoteInboundIds || (isset($client['expiryTime']) && !is_int($client['expiryTime'])) || (isset($client['totalGB']) && !is_int($client['totalGB']))) {
            ++$summary->invalid;

            return null;
        }
        $inbounds = [];
        foreach ($remoteInboundIds as $remoteInboundId) {
            $inbound = $this->entityManager->getRepository(VpnInbound::class)->findOneBy(['panel' => $panelId, 'remoteInboundId' => (string) $remoteInboundId]);
            if (!$inbound instanceof VpnInbound) {
                ++$summary->unknownInbound;

                return null;
            }
            $inbounds[] = $inbound;
        }
        // The links as the customer's subscription renders them (docs/design/multi-inbound-plans.md 9.1): subLinks, or
        // links/{email} without a subId. A client on several inbounds never gets links built from its primary alone.
        $answer = null === $subId ? $this->api->links($panel, $email, false, true) : $this->api->subLinks($panel, $subId, false);

        return [
            'uuid' => $uuid,
            'email' => $email,
            'subId' => $subId,
            'telegramId' => $telegramId,
            'inbounds' => $inbounds,
            'remoteInboundId' => $remoteInboundIds[0],
            'client' => $client,
            'usedBytes' => $usage[strtolower($email)] ?? ModernClientsApi::usedBytes($client),
            'links' => $answer['links'],
        ];
    }

    /**
     * @param list<array{telegramId: string}> $planned
     * @param array<string, true>             $owners
     * @param array<string, true>             $matched
     */
    private function countOwners(array $planned, array &$owners, array &$matched, PanelClientImportSummary $summary): void
    {
        $ids = array_values(array_unique(array_map(static fn (array $p): string => $p['telegramId'], $planned)));
        $unseen = array_values(array_filter($ids, static fn (string $id): bool => !isset($owners[$id])));
        $existing = [] === $unseen ? [] : array_flip(array_map('strval', $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT telegram_id FROM telegram_account WHERE telegram_id IN (?)',
            [$unseen],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        )));
        foreach ($unseen as $id) {
            $owners[$id] = true;
            if (isset($existing[$id])) {
                $matched[$id] = true;
                ++$summary->existingUsers;
            } else {
                ++$summary->newUsers;
            }
        }
    }

    /**
     * One transaction for the page: accounts for owners without one, the services, their additional inbounds, and the
     * notices their state would trigger now recorded as sent (ServiceNotificationService::markCurrentStateNotified()).
     * The entity manager is cleared afterwards; the panel is returned freshly loaded.
     *
     * @param list<array{uuid: string, email: string, subId: ?string, telegramId: string, inbounds: list<VpnInbound>, remoteInboundId: int, client: array<string, mixed>, usedBytes: ?int, links: ?list<string>}> $planned
     */
    private function write(int $panelId, array $planned): VpnPanel
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $panel = $this->entityManager->find(VpnPanel::class, $panelId) ?? throw new \RuntimeException('The panel was deleted during the import.');
            /** @var array<string, TelegramAccount> $accounts */
            $accounts = [];
            $now = new \DateTimeImmutable();
            foreach ($planned as $import) {
                $telegramId = $import['telegramId'];
                $accounts[$telegramId] ??= $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['telegramId' => $telegramId])
                    ?? $this->users->createAccount($telegramId);
                $service = $this->service($panel, $accounts[$telegramId], $import, $now);
                $this->entityManager->persist($service);
                foreach (array_slice($import['inbounds'], 1) as $additional) {
                    $row = new VpnServiceInbound($service, $additional, $now);
                    $row->recordAttempt(true, null, $now);
                    $this->entityManager->persist($row);
                }
                $this->notifications->markCurrentStateNotified($service, self::SOURCE);
            }
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
        $this->entityManager->clear();

        return $this->entityManager->find(VpnPanel::class, $panelId) ?? throw new \RuntimeException('The panel was deleted during the import.');
    }

    /**
     * @param array{uuid: string, email: string, subId: ?string, inbounds: list<VpnInbound>, remoteInboundId: int, client: array<string, mixed>, usedBytes: ?int, links: ?list<string>} $import
     */
    private function service(VpnPanel $panel, TelegramAccount $account, array $import, \DateTimeImmutable $now): VpnService
    {
        $client = $import['client'];
        $primary = $import['inbounds'][0];
        $expiryMs = (int) ($client['expiryTime'] ?? 0);
        // 0 = unlimited; negative = a duration that starts at first use, no date yet (as the driver reads it).
        $expiresAt = $expiryMs > 0 ? (new \DateTimeImmutable())->setTimestamp(intdiv($expiryMs, 1000)) : null;
        $totalBytes = (int) ($client['totalGB'] ?? 0);
        $limitBytes = $totalBytes > 0 ? $totalBytes : null;
        $usedBytes = null === $import['usedBytes'] ? null : max(0, $import['usedBytes']);
        $limitIp = (int) ($client['limitIp'] ?? 0);
        $createdMs = is_numeric($client['createdAt'] ?? null) ? (int) $client['createdAt'] : 0;
        $links = array_values(array_filter($import['links'] ?? [], static fn (string $link): bool => '' !== trim($link)));

        return (new VpnService())
            ->setUser($account->getUser())
            ->setOrder(null)
            ->setSoldByReseller(null)
            ->setPanel($panel)
            ->setInbound($primary)
            ->setRemoteId($this->remoteIds->format($panel->getId(), $primary->getId(), (string) $import['remoteInboundId'], $import['uuid'], $import['email'], $import['subId']))
            ->setUsername($import['email'])
            ->setClientUuid($import['uuid'])
            ->setClientEmail($import['email'])
            ->setSubId($import['subId'])
            ->setSubscriptionUrl(null === $import['subId'] ? null : SubscriptionUrl::build($panel, $import['subId']))
            ->setIpLimit($limitIp > 0 ? $limitIp : null)
            ->setConfigLinks($links)
            ->setConfigText([] === $links ? null : implode("\n", $links))
            ->setLastAccessInfoSyncedAt($now)
            ->setStatus(self::status($client, $expiresAt, $limitBytes, $usedBytes, $now))
            ->setStartsAt($createdMs > 0 ? (new \DateTimeImmutable())->setTimestamp(intdiv($createdMs, 1000)) : null)
            ->setExpiresAt($expiresAt)
            ->setTrafficLimitBytes($limitBytes)
            ->setTrafficUsedBytes($usedBytes)
            // GB as the usage sync stores them (whole GB, rounded down), so the next sync changes nothing.
            ->setTrafficLimitGb(null === $limitBytes ? null : intdiv($limitBytes, self::BYTES_PER_GB))
            ->setTrafficUsedGb(null === $usedBytes ? null : intdiv($usedBytes, self::BYTES_PER_GB))
            ->setLastUsageSyncedAt(null === $usedBytes ? null : $now)
            ->setLastStatusCheckedAt($now)
            ->setImportedAt($now);
    }

    /**
     * The status the bot's own rules give this client: past its expiry = expired (ServiceExpiryChecker); out of traffic
     * = suspended (ServiceAutoSuspendService); disabled on the panel = suspended (an admin's suspend); else active.
     *
     * @param array<string, mixed> $client
     */
    private static function status(array $client, ?\DateTimeImmutable $expiresAt, ?int $limitBytes, ?int $usedBytes, \DateTimeImmutable $now): string
    {
        return match (true) {
            null !== $expiresAt && $expiresAt < $now => VpnServiceStatus::EXPIRED,
            null !== $limitBytes && null !== $usedBytes && $usedBytes >= $limitBytes => VpnServiceStatus::SUSPENDED,
            false === ($client['enable'] ?? true) => VpnServiceStatus::SUSPENDED,
            default => VpnServiceStatus::ACTIVE,
        };
    }

    /**
     * @param array<string, mixed> $client
     */
    private static function uuidOf(array $client): ?string
    {
        $uuid = trim((string) ($client['uuid'] ?? $client['id'] ?? ''));

        return ClientUuid::isUuid($uuid) ? strtolower($uuid) : null;
    }

    /**
     * @param array<string, mixed> $client
     */
    private static function subIdOf(array $client): ?string
    {
        $subId = trim((string) ($client['subId'] ?? ''));

        return '' === $subId ? null : $subId;
    }

    private static function telegramIdOf(mixed $tgId): ?string
    {
        if (is_string($tgId) && 1 === preg_match('/^\s*\d+\s*$/', $tgId)) {
            $tgId = (int) $tgId;
        }

        return is_int($tgId) && $tgId > 0 && $tgId <= self::MAX_TELEGRAM_ID ? (string) $tgId : null;
    }

    private function knownIdentities(int $panelId): PanelClientIdentities
    {
        $known = new PanelClientIdentities();
        foreach ($this->entityManager->getConnection()->iterateAssociative('SELECT client_uuid, client_email, sub_id, panel_id FROM vpn_service') as $row) {
            $known->add(
                null === $row['client_uuid'] ? null : strtolower((string) $row['client_uuid']),
                null === $row['client_email'] ? null : (string) $row['client_email'],
                null === $row['sub_id'] ? null : (string) $row['sub_id'],
                null === $row['panel_id'] ? null : (int) $row['panel_id'],
            );
        }

        return $known;
    }
}
