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

        $driver = $this->driverRegistry->resolve($panel);
        $driverType = $panel?->getType() ?? 'dummy';
        $created = $driver->createService(new CreateVpnServiceRequest(
            username: sprintf('tg_%s_order_%d', $telegramId, $order->getId()),
            durationDays: $order->getPlan()->getDurationDays(),
            trafficLimitGb: $order->getPlan()->getTrafficGb(),
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
            ->setExpiresAt((new \DateTimeImmutable())->modify('+'.$order->getPlan()->getDurationDays().' days'))
            ->setTrafficLimitGb($order->getPlan()->getTrafficGb())
            ->setTrafficUsedGb(0);

        $links = $this->vpnAccessLinkGenerator->generate($vpnService);
        $configLinks = array_values(array_filter((array) ($links['configLinks'] ?? []), static fn (mixed $link): bool => '' !== trim((string) $link)));
        $missing = array_values(array_filter((array) ($links['missing'] ?? []), static fn (mixed $item): bool => '' !== trim((string) $item)));
        $configWarning = [] !== $missing
            ? '⚠️ لینک اتصال قابل تولید نیست. فیلدهای ناقص: '.implode(', ', $missing)
            : null;
        $existingConfigText = trim((string) ($vpnService->getConfigText() ?? ''));
        $finalConfigText = [] !== $configLinks
            ? (string) $configLinks[0]
            : ('' !== $existingConfigText ? $existingConfigText : $configWarning);

        $vpnService
            ->setSubscriptionUrl($links['subscriptionUrl'] ?? $vpnService->getSubscriptionUrl())
            ->setConfigLinks($configLinks)
            ->setConfigText($finalConfigText)
            ->setLastAccessInfoSyncedAt(new \DateTimeImmutable());

        $order
            ->setStatus(OrderStatus::PROVISIONED)
            ->setProvisionedAt(new \DateTimeImmutable());

        $this->entityManager->persist($vpnService);

        return $vpnService;
    }
}
