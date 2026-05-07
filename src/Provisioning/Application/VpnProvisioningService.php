<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Entity\Order;
use App\Entity\TelegramAccount;
use App\Entity\VpnPanel;
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
    ) {
    }

    public function provisionOrder(Order $order): VpnService
    {
        $panel = $this->entityManager->getRepository(VpnPanel::class)->findOneBy(['isActive' => true], ['id' => 'ASC']);
        $telegram = $this->entityManager->getRepository(TelegramAccount::class)->findOneBy(['user' => $order->getUser()]);
        $telegramId = $telegram?->getTelegramId() ?? (string) $order->getUser()->getId();

        $driver = $this->driverRegistry->resolve($panel instanceof VpnPanel ? $panel : null);
        $created = $driver->createService(new CreateVpnServiceRequest(
            username: 'tg_'.$telegramId,
            durationDays: $order->getPlan()->getDurationDays(),
            trafficLimitGb: $order->getPlan()->getTrafficGb(),
            meta: ['orderId' => $order->getId()],
        ), $panel instanceof VpnPanel ? $panel : null);

        $vpnService = (new VpnService())
            ->setUser($order->getUser())
            ->setOrder($order)
            ->setPanel($panel instanceof VpnPanel ? $panel : null)
            ->setRemoteId($created->remoteId)
            ->setUsername($created->username)
            ->setSubscriptionUrl($created->subscriptionUrl)
            ->setConfigText($created->configText)
            ->setStatus(VpnServiceStatus::ACTIVE)
            ->setStartsAt(new \DateTimeImmutable())
            ->setExpiresAt((new \DateTimeImmutable())->modify('+'.$order->getPlan()->getDurationDays().' days'))
            ->setTrafficLimitGb($order->getPlan()->getTrafficGb())
            ->setTrafficUsedGb(0);

        $order
            ->setStatus(OrderStatus::PROVISIONED)
            ->setProvisionedAt(new \DateTimeImmutable());

        $this->entityManager->persist($vpnService);

        return $vpnService;
    }
}
