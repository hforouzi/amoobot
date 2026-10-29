<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Order;
use App\Entity\VpnService;
use App\Shop\Domain\OrderType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Which service an order is about, and whether the person who placed it may still see that service's access (links,
 * QR, subscription page, the service screen). A service can change owner after it was paid for (a claim link,
 * docs/design/reseller-and-claim.md 3.4; an admin's "Return to reseller"): from then on an order or payment view of the
 * previous owner (payment return page, Mini App order and payment screens, the bot's order detail) shows that the
 * payment went through, never the current owner's links. The owner is read from the database at that moment, never
 * from a possibly stale entity.
 */
class OrderServiceAccess
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** The service this order created or changed (renewal / add traffic: its target), whoever owns it now. */
    public function serviceOf(Order $order): ?VpnService
    {
        if (in_array($order->getType(), [OrderType::RENEWAL, OrderType::ADD_TRAFFIC], true)) {
            return $order->getTargetService();
        }
        if (null === $order->getId()) {
            return null;
        }
        $service = $this->entityManager->getRepository(VpnService::class)->findOneBy(['order' => $order], ['id' => 'DESC']);

        return $service instanceof VpnService ? $service : null;
    }

    /** That service, but only while it still belongs to the order's customer; null otherwise. */
    public function ownedServiceOf(Order $order): ?VpnService
    {
        $service = $this->serviceOf($order);

        return null !== $service && $this->ownedByOrderCustomer($order, $service) ? $service : null;
    }

    /** Whether the service belongs to the order's customer right now. */
    public function ownedByOrderCustomer(Order $order, VpnService $service): bool
    {
        if (null === $service->getId()) {
            return $service->getUser()->getId() === $order->getUser()->getId();
        }
        $owner = $this->entityManager->getConnection()->fetchOne('SELECT user_id FROM vpn_service WHERE id = ?', [$service->getId()]);

        return false !== $owner && (int) $owner === $order->getUser()->getId();
    }
}
