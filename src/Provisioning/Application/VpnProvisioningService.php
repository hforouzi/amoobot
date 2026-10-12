<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\Order;
use App\Entity\TelegramAccount;
use App\Entity\VpnInbound;
use App\Entity\VpnPanel;
use App\Entity\VpnService;
use App\Provisioning\Domain\Dto\AdoptedVpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\ExistingClientAwareDriverInterface;
use App\Provisioning\Domain\VpnPanelDriverInterface;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VpnProvisioningService
{
    private const BYTES_PER_GB = 1073741824;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
        private readonly FinalConfigLinkProvider $finalConfigLinkProvider,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
        private readonly LoggerInterface $logger,
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
     * @param bool $adoptExisting look for this order's client on the panel first (retry after a failed attempt)
     */
    public function provisionOrder(Order $order, array $meta = [], bool $adoptExisting = false): VpnService
    {
        $planInbound = $order->getPlan()->getInbound();
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
        $unlimitedDuration = true === ($orderMeta['unlimitedDuration'] ?? false) || $order->getPlan()->isUnlimitedDuration();
        $durationDays = $unlimitedDuration ? 0 : (int) ($orderMeta['durationDays'] ?? 0);
        $trafficLimitGb = (int) ($orderMeta['trafficGb'] ?? 0);
        if (!$unlimitedDuration && $durationDays <= 0) {
            $durationDays = $order->getPlan()->getDurationDays();
        }
        if ($trafficLimitGb <= 0) {
            $trafficLimitGb = (int) ($order->getPlan()->getTrafficGb() ?? 0);
        }
        $trafficLimitValue = $trafficLimitGb > 0 ? $trafficLimitGb : null;
        $username = '' !== $customFinalUsername
            ? $customFinalUsername
            : sprintf('tg_%s_order_%d', $telegramId, $order->getId());

        $driver = $this->driverRegistry->resolve($panel);
        $driverType = $panel?->getType() ?? 'dummy';
        $request = new CreateVpnServiceRequest(
            username: $username,
            durationDays: $durationDays,
            trafficLimitGb: $trafficLimitValue,
            ipLimit: $order->getPlan()->getIpLimit(),
            inbound: $planInbound,
            remoteInboundId: $planInbound?->getRemoteInboundId(),
            meta: array_merge([
                'orderId' => $order->getId(),
                'telegramId' => $telegramId,
                'planId' => $order->getPlan()->getId(),
                'planInboundId' => $planInbound?->getId(),
                'panelId' => $panel?->getId(),
                'driverType' => $driverType,
                'customOrder' => $isCustomOrder ? 1 : 0,
            ], $meta),
            clientUuid: $this->orderClientUuid($order),
        );
        $adopted = $adoptExisting ? $this->findAdoptableClient($driver, $request, $panel, $order, '' === $customFinalUsername) : null;
        $created = $adopted->service ?? $driver->createService($request, $panel);
        if (null !== $adopted?->trafficLimitGb) {
            $trafficLimitValue = $adopted->trafficLimitGb;
        }

        $vpnService = (new VpnService())
            ->setUser($order->getUser())
            ->setOrder($order)
            ->setPanel($panel)
            ->setInbound($planInbound)
            ->setRemoteId($created->remoteId)
            ->setUsername($created->username)
            ->setSubscriptionUrl($created->subscriptionUrl."/".$created->subId)
            ->setClientUuid($created->clientUuid)
            ->setClientEmail($created->clientEmail)
            ->setSubId($created->subId)
            ->setIpLimit($created->ipLimit ?? $order->getPlan()->getIpLimit())
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

        if ('sanaei_3xui' !== $panelType || ([] === $configLinks && '' === $createdConfigText)) {
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
            inboundId: $planInbound?->getId(),
            uuid: (string) ($vpnService->getClientUuid() ?? ''),
            subId: (string) ($vpnService->getSubId() ?? ''),
            generatedConfigLinkCount: count($finalLinks),
            configTextEmpty: '' === trim((string) ($finalConfigText ?? '')),
            subscriptionUrl: (string) ($finalSubscriptionUrl ?? ''),
            configTextPreview: (string) ($finalConfigText ?? ''),
            customOrder: $isCustomOrder,
            finalUsername: $username,
            trafficLimitGb: (int) ($trafficLimitValue ?? 0),
            durationDays: $durationDays
        );

        $order
            ->setStatus(OrderStatus::PROVISIONED)
            ->setProvisionedAt(new \DateTimeImmutable());

        $this->entityManager->persist($vpnService);

        return $vpnService;
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

    private function logProvisioningConfig(
        string $source,
        ?int $serviceId,
        ?int $inboundId,
        string $uuid,
        string $subId,
        int $generatedConfigLinkCount,
        bool $configTextEmpty,
        string $subscriptionUrl,
        string $configTextPreview,
        bool $customOrder,
        string $finalUsername,
        int $trafficLimitGb,
        int $durationDays
    ): void {
        error_log(sprintf(
            '[VpnProvisioningService] provisioning_config source="%s" service_id=%d inbound_id=%d uuid="%s" sub_id="%s" generated_config_link_count=%d config_text_empty=%s subscription_url="%s" custom_order=%s final_username="%s" traffic_limit_gb=%d duration_days=%d config_text_preview="%s"',
            $source,
            $serviceId ?? 0,
            $inboundId ?? 0,
            $uuid,
            $subId,
            $generatedConfigLinkCount,
            $configTextEmpty ? 'yes' : 'no',
            $subscriptionUrl,
            $customOrder ? 'yes' : 'no',
            $finalUsername,
            $trafficLimitGb,
            $durationDays,
            $this->sanitizeLogPreview($configTextPreview)
        ));
    }

    private function sanitizeLogPreview(string $value, int $max = 120): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ('' === $text) {
            return '';
        }

        return mb_substr($text, 0, $max);
    }
}
