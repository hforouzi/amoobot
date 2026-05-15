<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Entity\Plugin;
use Doctrine\ORM\EntityManagerInterface;

final class PluginRegistry
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Plugin>
     */
    public function all(): array
    {
        return $this->entityManager->getRepository(Plugin::class)
            ->createQueryBuilder('plugin')
            ->orderBy('plugin.installedAt', 'DESC')
            ->addOrderBy('plugin.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Plugin>
     */
    public function enabled(): array
    {
        return $this->entityManager->getRepository(Plugin::class)
            ->findBy(['status' => Plugin::STATUS_ENABLED], ['code' => 'ASC']);
    }

    public function findByCode(string $code): ?Plugin
    {
        $plugin = $this->entityManager->getRepository(Plugin::class)->findOneBy(['code' => $code]);

        return $plugin instanceof Plugin ? $plugin : null;
    }

    /**
     * @return list<Plugin>
     */
    public function paymentGatewayPlugins(): array
    {
        return $this->entityManager->getRepository(Plugin::class)
            ->findBy([
                'status' => Plugin::STATUS_ENABLED,
                'type' => Plugin::TYPE_PAYMENT_GATEWAY,
            ], ['code' => 'ASC']);
    }
}
