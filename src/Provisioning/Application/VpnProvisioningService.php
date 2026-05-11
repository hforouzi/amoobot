<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\Order;
use App\Entity\TelegramAccount;
use App\Entity\VpnInbound;
use App\Entity\VpnService;
use App\Provisioning\Domain\Dto\CreateVpnServiceRequest;
use App\Provisioning\Domain\VpnServiceStatus;
use App\Provisioning\Infrastructure\VpnPanelDriverRegistry;
use App\Shop\Domain\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;

class VpnProvisioningService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VpnPanelDriverRegistry $driverRegistry,
        private readonly VpnAccessLinkGenerator $vpnAccessLinkGenerator,
    ) {
    }

    /**
     * @param array<string, scalar|null> $meta
     */
    public function provisionOrder(Order $order, array $meta = []): VpnService
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
        $isCustomOrder = true === ($orderMeta['custom'] ?? false);
        $customFinalUsername = $isCustomOrder ? trim((string) ($orderMeta['finalUsername'] ?? '')) : '';
        $durationDays = $isCustomOrder ? (int) ($orderMeta['durationDays'] ?? 0) : 0;
        $trafficLimitGb = $isCustomOrder ? (int) ($orderMeta['trafficGb'] ?? 0) : 0;
        if ($durationDays <= 0) {
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
        $created = $driver->createService(new CreateVpnServiceRequest(
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
        ), $panel);

        $vpnService = (new VpnService())
            ->setUser($order->getUser())
            ->setOrder($order)
            ->setPanel($panel)
            ->setInbound($planInbound)
            ->setRemoteId($created->remoteId)
            ->setUsername($created->username)
            ->setSubscriptionUrl($created->subscriptionUrl)
            ->setClientUuid($created->clientUuid)
            ->setClientEmail($created->clientEmail)
            ->setSubId($created->subId)
            ->setIpLimit($created->ipLimit ?? $order->getPlan()->getIpLimit())
            ->setConfigLinks($created->configLinks)
            ->setConfigText($created->configText)
            ->setStatus(VpnServiceStatus::ACTIVE)
            ->setStartsAt(new \DateTimeImmutable())
            ->setExpiresAt((new \DateTimeImmutable())->modify('+'.$durationDays.' days'))
            ->setTrafficLimitGb($trafficLimitValue)
            ->setTrafficUsedGb(0);

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
        $finalConfigText = [] !== $configLinks
            ? implode("\n", $configLinks)
            : ('' !== $existingConfigText ? $existingConfigText : $configWarning);

        $finalSubscriptionUrl = $subscriptionUrl ?? $vpnService->getSubscriptionUrl();

        $vpnService
            ->setSubscriptionUrl($finalSubscriptionUrl)
            ->setConfigLinks($configLinks)
            ->setConfigText($finalConfigText)
            ->setLastAccessInfoSyncedAt(new \DateTimeImmutable());

        $source = trim((string) ($meta['source'] ?? ''));
        $this->logProvisioningConfig(
            source: $source,
            serviceId: $vpnService->getId(),
            inboundId: $planInbound?->getId(),
            uuid: (string) ($vpnService->getClientUuid() ?? ''),
            subId: (string) ($vpnService->getSubId() ?? ''),
            generatedConfigLinkCount: count($configLinks),
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
