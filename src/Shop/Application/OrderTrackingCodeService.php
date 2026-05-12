<?php

declare(strict_types=1);

namespace App\Shop\Application;

use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

final class OrderTrackingCodeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function assignIfMissing(Order $order): void
    {
        if (null !== $order->getTrackingCode() && '' !== trim((string) $order->getTrackingCode())) {
            return;
        }

        $order->setTrackingCode($this->generateUnique());
    }

    public function generateUnique(): string
    {
        $date = (new \DateTimeImmutable())->format('Ymd');

        for ($i = 0; $i < 50; ++$i) {
            $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
            $candidate = sprintf('AMO-%s-%s', $date, $random);
            $exists = (int) $this->entityManager->getRepository(Order::class)
                ->createQueryBuilder('o')
                ->select('COUNT(o.id)')
                ->where('o.trackingCode = :trackingCode')
                ->setParameter('trackingCode', $candidate)
                ->getQuery()
                ->getSingleScalarResult();

            if (0 === $exists) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate unique order tracking code.');
    }
}

