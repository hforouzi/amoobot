<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\Order;
use App\Entity\TelegramAccount;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Entity\VpnServiceInbound;
use App\Provisioning\Domain\Dto\AdoptedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\ExistingClientAwareDriverInterface;
use App\Provisioning\Domain\InboundAttachDriverInterface;
use App\Provisioning\Domain\VpnPanelDriverInterface;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\SecretRedactor;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shop\Application\MultiInboundSettings;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VpnProvisioningService
{
    private const BYTES_PER_GB = 1073741824;
    public const SWITCHED_OFF = 'not attached: plans with several inbounds were switched off when the service was created (Settings, Plans tab)';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
        private readonly LoggerInterface $logger,
        private readonly MultiInboundSettings $multiInbound,
    ) {
    }

    /**
     * Client UUID for an order's panel client. Deterministic, so a retried provisioning can find
     * the client an earlier attempt created; keyed with the app secret because for VLESS/VMess the
     * UUID is the connection credential and must not be computable from the order id.
     */
    public function orderClientUuid(Order $order): string
    {
        $hash = hash_hmac('sha256', 'amoobot-order-client:'.($order->getId() ?? 0), $this->appSecret);
        // Format as an RFC 4122 version-4 style UUID (version and variant bits set).
        $hex = substr($hash, 0, 12).'4'.substr($hash, 13, 3).dechex(8 | (hexdec($hash[16]) & 3)).substr($hash, 17, 15);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /**
     * @param array<string, scalar|null> $meta
     * @param bool $adoptExisting look for this order's client on the panel first and adopt it (drivers
     *                            implementing ExistingClientAwareDriverInterface; others always create)
     */
    public function provisionOrder(Order $order, array $meta = [], bool $adoptExisting = false): VpnService
    {
        [$planInbound, $additionalInbounds] = $this->orderInbounds($order);
        if ($planInbound instanceof VpnInbound && !$planInbound->isActive()) {
            throw new \RuntimeException('Selected VPN inbound is inactive.');
        }

        $panel = $planInbound?->getPanel();
        if (null !== $panel && !$panel->isActive()) {
            throw new \RuntimeException('Selected VPN panel is inactive.');
        }

        $telegram = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $order->getUser()]);
        $telegramId = $telegram?->getTelegramId() ?? (string) $order->getUser()->getId();
        $orderMeta = is_array($order->getMetadata()) ? $order->getMetadata() : [];
        $isCustomOrder = true === ($orderMeta['custom'] ?? false) || array_key_exists('finalUsername', $orderMeta);
        $customFinalUsername = trim((string) ($orderMeta['finalUsername'] ?? ''));
        $unlimitedDuration = true === ($orderMeta['unlimitedDuration'] ?? false) || $order->requirePlan()->isUnlimitedDuration();
        $durationDays = $unlimitedDuration ? 0 : (int) ($orderMeta['durationDays'] ?? 0);
        $trafficLimitGb = (int) ($orderMeta['trafficGb'] ?? 0);
        if (!$unlimitedDuration && $durationDays <= 0) {
            $durationDays = $order->requirePlan()->getDurationDays();
        }
        if ($trafficLimitGb <= 0) {
            $trafficLimitGb = (int) ($order->requirePlan()->getTrafficGb() ?? 0);
        }
        $trafficLimitValue = $trafficLimitGb > 0 ? $trafficLimitGb : null;
        $username = '' !== $customFinalUsername
            ? $customFinalUsername
            : sprintf('tg_%s_order_%d', $telegramId, $order->getId());

        $driver = $this->driverRegistry->resolve($panel);
        $driverType = $panel?->getType() ?? 'dummy';
        [$attachable, $notAttached] = $this->additionalInbounds($additionalInbounds, $driver, $panel);
        $request = new CreateVpnServiceRequest(
            username: $username,
            durationDays: $durationDays,
            trafficLimitGb: $trafficLimitValue,
            ipLimit: $order->requirePlan()->getIpLimit(),
            inbound: $planInbound,
            remoteInboundId: $planInbound?->getRemoteInboundId(),
            meta: array_merge([
                'orderId' => $order->getId(),
                'telegramId' => $telegramId,
                'planId' => $order->requirePlan()->getId(),
                'planInboundId' => $planInbound?->getId(),
                'panelId' => $panel?->getId(),
                'driverType' => $driverType,
                'customOrder' => $isCustomOrder ? 1 : 0,
            ], $meta),
            clientUuid: $this->orderClientUuid($order),
            additionalInbounds: $attachable,
        );
        $adopted = $adoptExisting ? $this->findAdoptableClient($driver, $request, $panel, $order, '' === $customFinalUsername) : null;
        $created = $adopted->service ?? $driver->createService($request, $panel);
        if (null !== $adopted?->trafficLimitGb) {
            $trafficLimitValue = $adopted->trafficLimitGb;
        }

        $vpnService = (new VpnService())
            ->setUser($order->getUser())
            ->setOrder($order)
            ->setSoldByReseller($order->getReseller())
            ->setPanel($panel)
            ->setInbound($planInbound)
            ->setRemoteId($created->remoteId)
            ->setUsername($created->username)
            ->setSubscriptionUrl($created->subscriptionUrl)
            ->setClientUuid($created->clientUuid)
            ->setClientEmail($created->clientEmail)
            ->setSubId($created->subId)
            ->setIpLimit($created->ipLimit ?? $order->requirePlan()->getIpLimit())
            ->setConfigLinks($created->configLinks)
            ->setConfigText($created->configText)
            ->setStatus(VpnServiceStatus::ACTIVE)
            ->setStartsAt(new \DateTimeImmutable())
            ->setExpiresAt($adopted->expiresAt ?? ($unlimitedDuration ? null : (new \DateTimeImmutable())->modify('+'.$durationDays.' days')))
            ->setTrafficLimitGb($trafficLimitValue)
            ->setTrafficUsedGb(0)
            ->setTrafficLimitBytes(null === $trafficLimitValue ? null : ($trafficLimitValue * self::BYTES_PER_GB))
            ->setTrafficUsedBytes(0);

        $panelType = strtolower(trim((string) ($panel?->getType() ?? '')));
        $createdConfigText = trim((string) ($created->configText ?? ''));
        $createdConfigLinks = array_values(array_filter((array) ($created->configLinks ?? []), static fn (mixed $link): bool => '' !== trim((string) $link)));
        $configLinks = $createdConfigLinks;
        $missing = [];
        $subscriptionUrl = $vpnService->getSubscriptionUrl();

        // A service on several inbounds is never given links built from the primary alone: without the panel's links
        // it has its subscription URL only (docs/design/multi-inbound-plans.md 9.1). One created with the switch off is
        // on its primary alone, so it is treated as a single-inbound service here.
        $multiInbound = [] !== $attachable || [] !== array_filter($notAttached, static fn (array $n): bool => self::SWITCHED_OFF !== $n[1]);
        if ('sanaei_3xui' !== $panelType || ([] === $configLinks && '' === $createdConfigText && !$multiInbound)) {
            $links = $this->vpnAccessLinkGenerator->generate($vpnService);
            $configLinks = array_values(array_filter((array) ($links['configLinks'] ?? []), static fn (mixed $link): bool => '' !== trim((string) $link)));
            $missing = array_values(array_filter((array) ($links['missing'] ?? []), static fn (mixed $item): bool => '' !== trim((string) $item)));
            $subscriptionUrl = $links['subscriptionUrl'] ?? $subscriptionUrl;
        }

        $configWarning = [] !== $missing ? '⚠️ لینک اتصال قابل تولید نیست. فیلدهای ناقص: '.implode(', ', $missing) : null;
        $existingConfigText = trim((string) ($vpnService->getConfigText() ?? ''));
        $finalLinkSet = $this->finalConfigLinkProvider->deduplicateAndPreferFormattedForService(
            $vpnService,
            $createdConfigLinks,
            $configLinks,
            'provisioning_service_created'
        );
        $finalLinks = $finalLinkSet->finalLinks;
        $finalConfigText = [] !== $finalLinks
            ? implode("\n", $finalLinks)
            : ('' !== $existingConfigText ? $existingConfigText : $configWarning);

        $finalSubscriptionUrl = $subscriptionUrl ?? $vpnService->getSubscriptionUrl();

        $vpnService
            ->setSubscriptionUrl($finalSubscriptionUrl)
            ->setConfigLinks($finalLinks)
            ->setConfigText($finalConfigText)
            ->setLastAccessInfoSyncedAt(new \DateTimeImmutable());

        $source = trim((string) ($meta['source'] ?? ''));
        $this->logProvisioningConfig(
            source: $source,
            serviceId: $vpnService->getId(),
            orderId: $order->getId(),
            panelId: $vpnService->getPanel()?->getId(),
            inboundId: $planInbound?->getId(),
            uuid: (string) ($vpnService->getClientUuid() ?? ''),
            subId: (string) ($vpnService->getSubId() ?? ''),
            generatedConfigLinkCount: count($finalLinks),
            configTextEmpty: '' === trim((string) ($finalConfigText ?? '')),
            subscriptionUrlPresent: '' !== trim((string) ($finalSubscriptionUrl ?? '')),
            customOrder: $isCustomOrder,
            finalUsername: $username,
            trafficLimitGb: (int) ($trafficLimitValue ?? 0),
            durationDays: $durationDays
        );

        $order
            ->setStatus(OrderStatus::PROVISIONED)
            ->setProvisionedAt(new \DateTimeImmutable());

        $this->entityManager->persist($vpnService);
        $this->recordAttachments($vpnService, $created->attachments, $notAttached);

        return $vpnService;
    }

    /**
     * The order's primary and additional inbounds: from the snapshot taken when the order was created
     * (Order::planInboundSnapshot(), M2c), so a plan edited since does not change what was sold; an order without one
     * (created before M2c) uses the live plan.
     *
     * - A single-inbound order keeps using the plan's current primary, as before.
     * - A multi-inbound order uses the snapshot's primary while it exists (the additional inbounds were chosen on its
     *   panel), else the plan's current one.
     * - An additional inbound of the snapshot that was deleted since cannot have a row: its id is recorded in the
     *   order's metadata (Order::PLAN_INBOUNDS_MISSING) and the admins are alerted after the settlement
     *   (InboundAttachAlerts::missingInbounds()). The primary is still delivered.
     *
     * @return array{?VpnInbound, list<VpnInbound>}
     */
    private function orderInbounds(Order $order): array
    {
        $plan = $order->requirePlan();
        $snapshot = $order->planInboundSnapshot();
        if (null === $snapshot) {
            return [$plan->getInbound(), $plan->getAdditionalInbounds()];
        }
        if ([] === $snapshot['additional']) {
            return [$plan->getInbound(), []];
        }
        $repository = $this->entityManager->getRepository(VpnInbound::class);
        $additional = [];
        $missing = [];
        foreach ($snapshot['additional'] as $id) {
            $inbound = $repository->find($id);
            if ($inbound instanceof VpnInbound) {
                $additional[] = $inbound;
            } else {
                $missing[] = $id;
            }
        }
        if ([] !== $missing) {
            $order->setMetadata([Order::PLAN_INBOUNDS_MISSING => $missing] + ($order->getMetadata() ?? []));
            $this->logger->warning('provisioning_snapshot_inbounds_missing', ['order_id' => $order->getId(), 'missing' => count($missing)]);
        }
        $primary = null === $snapshot['primary'] ? null : $repository->find($snapshot['primary']);

        return [$primary instanceof VpnInbound ? $primary : $plan->getInbound(), $additional];
    }

    /**
     * The order's additional inbounds: the ones to attach now, and the ones recorded as not attached without asking the
     * panel (docs/design/multi-inbound-plans.md 3, D2/D3: the order is paid, so the primary is delivered and these wait
     * for the scheduled retry or "Retry attach"):
     * - plans.multi_inbound_enabled is off (switched off after the order was taken; M2c F3): on ANY panel, legacy and
     *   other types included, the primary is provisioned exactly as a single-inbound plan and nothing else is sent to
     *   the panel. Every additional inbound gets a failed row with that reason; nothing acts on such rows while the
     *   switch stays off (the scheduled retry, "Retry attach" and the alerts all need it on), so they are picked up
     *   only once it is turned on again;
     * - the inbound became inactive since;
     * - the inbound is on another panel than the service (the plan's primary was moved since).
     *
     * With the switch on, a panel whose driver cannot attach (legacy mode, another type) fails closed: never the primary
     * alone (PROVISIONING_FAILED and the admins' alert).
     *
     * @param list<VpnInbound> $inbounds
     *
     * @return array{list<VpnInbound>, list<array{VpnInbound, string}>}
     */
    private function additionalInbounds(array $inbounds, VpnPanelDriverInterface $driver, ?VpnPanel $panel): array
    {
        if ([] === $inbounds) {
            return [[], []];
        }
        if (!$this->multiInbound->enabled()) {
            return [[], array_map(static fn (VpnInbound $inbound): array => [$inbound, self::SWITCHED_OFF], $inbounds)];
        }
        if (!$driver instanceof InboundAttachDriverInterface) {
            throw new \RuntimeException(InboundAttachDriverInterface::MODERN_PANEL_REQUIRED);
        }
        $attachable = [];
        $notAttached = [];
        foreach ($inbounds as $inbound) {
            if (!$inbound->isActive()) {
                $notAttached[] = [$inbound, 'not attached: the inbound is inactive'];
            } elseif (null === $panel || $inbound->getPanel()->getId() !== $panel->getId()) {
                $notAttached[] = [$inbound, ServiceInboundAttacher::OTHER_PANEL];
            } else {
                $attachable[] = $inbound;
            }
        }

        return [$attachable, $notAttached];
    }

    /**
     * One vpn_service_inbound row per additional inbound, upserted by (service, inbound).
     *
     * @param list<\App\Provisioning\Domain\Dto\InboundAttachment> $attachments
     * @param list<array{VpnInbound, string}>                         $notAttached
     */
    private function recordAttachments(VpnService $service, array $attachments, array $notAttached): void
    {
        $now = new \DateTimeImmutable();
        $results = array_map(static fn ($a): array => [$a->inbound, $a->attached, $a->error, $a->pending], $attachments);
        foreach ($notAttached as [$inbound, $reason]) {
            $results[] = [$inbound, false, $reason, false];
        }
        foreach ($results as [$inbound, $attached, $error, $pending]) {
            $row = null === $service->getId() ? null : $this->entityManager->getRepository(VpnServiceInbound::class)->findOneBy(['service' => $service, 'inbound' => $inbound]);
            if (!$row instanceof VpnServiceInbound) {
                $row = new VpnServiceInbound($service, $inbound, $now);
                $this->entityManager->persist($row);
            }
            if ($pending) {
                $row->recordPending((string) $error, !in_array($error, [InboundAttachDriverInterface::NOT_TRIED, InboundAttachDriverInterface::AFTER_SALE], true), $now);
            } else {
                $row->recordAttempt($attached, null === $error ? null : ServiceErrorText::redact($service, $error, 255), $now);
            }
        }
        $failed = count(array_filter($results, static fn (array $r): bool => !$r[1]));
        if ($failed > 0) {
            $this->logger->warning('provisioning_inbounds_not_attached', ['order_id' => $service->getOrder()?->getId(), 'failed' => $failed, 'of' => count($results)]);
        }
    }

    /**
     * An earlier attempt may have created the client on the panel and then failed (database error,
     * timeout, verification). Adopting it avoids a second client for one order.
     *
     * A UUID match always belongs to this order (the UUID is derived from it). An email match is
     * accepted only for generated names (tg_<telegram>_order_<id>): a customer-chosen username may
     * belong to someone else's client, so then provisioning stops for a manual decision.
     */
    private function findAdoptableClient(VpnPanelDriverInterface $driver, CreateVpnServiceRequest $request, ?VpnPanel $panel, Order $order, bool $generatedName): ?AdoptedVpnService
    {
        if (!$driver instanceof ExistingClientAwareDriverInterface) {
            return null;
        }

        $adopted = $driver->findExistingService($request, $panel);
        if (!$adopted instanceof AdoptedVpnService) {
            return null;
        }

        if (AdoptedVpnService::MATCHED_BY_UUID !== $adopted->matchedBy && !$generatedName) {
            throw new \RuntimeException(sprintf('A panel client named "%s" already exists but its UUID does not belong to order #%d; resolve it on the panel, then retry.', $request->username, $order->getId() ?? 0));
        }

        $this->logger->warning('Adopted existing panel client instead of creating a second one.', ['order_id' => $order->getId(), 'matched_by' => $adopted->matchedBy]);

        return $adopted;
    }

    /**
     * Ids, counts and flags only: the client UUID and subscription id appear as their last 4 characters
     * (SecretRedactor::mask), the subscription URL and config links not at all, since each one is a customer's access.
     */
    private function logProvisioningConfig(
        string $source,
        ?int $serviceId,
        ?int $orderId,
        ?int $panelId,
        ?int $inboundId,
        string $uuid,
        string $subId,
        int $generatedConfigLinkCount,
        bool $configTextEmpty,
        bool $subscriptionUrlPresent,
        bool $customOrder,
        string $finalUsername,
        int $trafficLimitGb,
        int $durationDays
    ): void {
        error_log(sprintf(
            '[VpnProvisioningService] provisioning_config source="%s" service_id=%d order_id=%d panel_id=%d inbound_id=%d uuid="%s" sub_id="%s" generated_config_link_count=%d config_text_empty=%s subscription_url_present=%s custom_order=%s final_username="%s" traffic_limit_gb=%d duration_days=%d',
            $source,
            $serviceId ?? 0,
            $orderId ?? 0,
            $panelId ?? 0,
            $inboundId ?? 0,
            SecretRedactor::mask($uuid),
            SecretRedactor::mask($subId),
            $generatedConfigLinkCount,
            $configTextEmpty ? 'yes' : 'no',
            $subscriptionUrlPresent ? 'yes' : 'no',
            $customOrder ? 'yes' : 'no',
            $finalUsername,
            $trafficLimitGb,
            $durationDays
        ));
    }
}
