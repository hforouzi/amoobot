<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use App\Plugin\PluginAutoloader;
use App\Plugin\PluginRegistry;
use Psr\Log\LoggerInterface;

final class PaymentGatewayRegistry
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $driversByType = [];

    /**
     * @param iterable<PaymentGatewayInterface> $drivers
     */
    public function __construct(
        iterable $drivers,
        private readonly PluginRegistry $pluginRegistry,
        private readonly PluginAutoloader $pluginAutoloader,
        private readonly LoggerInterface $logger,
    )
    {
        foreach ($drivers as $driver) {
            $type = trim($driver->getType());
            if ('' === $type) {
                continue;
            }
            $this->driversByType[$type] = $driver;
        }
    }

    public function resolveByType(string $type): PaymentGatewayInterface
    {
        $type = trim($type);
        if (isset($this->driversByType[$type])) {
            return $this->driversByType[$type];
        }

        $plugin = $this->pluginRegistry->findByCode($type);
        if ($plugin instanceof Plugin && Plugin::STATUS_ENABLED === $plugin->getStatus() && Plugin::TYPE_PAYMENT_GATEWAY === $plugin->getType()) {
            return $this->createPluginAdapter($plugin);
        }

        throw new \RuntimeException(sprintf('No payment gateway driver registered for type "%s".', $type));
    }

    public function supportsType(string $type): bool
    {
        $type = trim($type);
        if (isset($this->driversByType[$type])) {
            return true;
        }

        $plugin = $this->pluginRegistry->findByCode($type);

        return $plugin instanceof Plugin
            && Plugin::STATUS_ENABLED === $plugin->getStatus()
            && Plugin::TYPE_PAYMENT_GATEWAY === $plugin->getType();
    }

    public function resolve(PaymentGateway $gateway): PaymentGatewayInterface
    {
        return $this->resolveByType($gateway->getType());
    }

    private function createPluginAdapter(Plugin $plugin): PluginPaymentGatewayDriverAdapter
    {
        $this->pluginAutoloader->register($plugin);

        $mainClass = trim((string) $plugin->getMainClass());
        if ('' === $mainClass || !class_exists($mainClass)) {
            $this->logger->warning('Plugin payment gateway class not found.', ['plugin' => $plugin->getCode()]);
            throw new \RuntimeException('Plugin payment gateway class was not found.');
        }

        $instance = new $mainClass();
        if (!$instance instanceof PaymentGatewayPluginInterface) {
            $this->logger->warning('Plugin payment gateway class has invalid interface.', ['plugin' => $plugin->getCode()]);
            throw new \RuntimeException('Plugin payment gateway class does not implement PaymentGatewayPluginInterface.');
        }

        return new PluginPaymentGatewayDriverAdapter($plugin, $instance);
    }
}

