<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure;

use App\Entity\PaymentGateway;
use App\Entity\Plugin;
use App\Payment\Domain\PaymentGatewayInterface;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PluginPaymentGatewayDriverAdapter;
use App\Plugin\PaymentPluginDoctor;
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
        private readonly PaymentPluginDoctor $pluginDoctor,
        private readonly LoggerInterface $logger,
    ) {
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
        if ($plugin instanceof Plugin) {
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
            && Plugin::TYPE_PAYMENT_GATEWAY === $plugin->getType()
            && $this->pluginDoctor->inspect($plugin)->ok();
    }

    public function resolve(PaymentGateway $gateway): PaymentGatewayInterface
    {
        $pluginCode = trim((string) ($gateway->getPluginCode() ?? ''));
        if ('' !== $pluginCode) {
            $plugin = $this->pluginRegistry->findByCode($pluginCode);
            if (!$plugin instanceof Plugin) {
                $this->logger->warning('Plugin payment gateway resolution failed: plugin_not_found.', [
                    'gateway_id' => $gateway->getId(),
                    'gateway_type' => $gateway->getType(),
                    'plugin_code' => $pluginCode,
                ]);
                throw new \RuntimeException(sprintf('plugin_missing: Plugin "%s" was not found.', $pluginCode));
            }

            return $this->createPluginAdapter($plugin);
        }

        return $this->resolveByType($gateway->getType());
    }

    private function createPluginAdapter(Plugin $plugin): PluginPaymentGatewayDriverAdapter
    {
        if (Plugin::TYPE_PAYMENT_GATEWAY !== $plugin->getType()) {
            throw new \RuntimeException(sprintf('plugin_runtime_error: Plugin "%s" is not a payment gateway plugin.', $plugin->getCode()));
        }

        if (Plugin::STATUS_ERROR === $plugin->getStatus()) {
            throw new \RuntimeException(sprintf('plugin_error: %s', trim((string) $plugin->getErrorMessage())));
        }

        if (Plugin::STATUS_ENABLED !== $plugin->getStatus()) {
            $this->logger->warning('Plugin payment gateway resolution failed: plugin_disabled.', [
                'plugin' => $plugin->getCode(),
                'status' => $plugin->getStatus(),
            ]);
            throw new \RuntimeException(sprintf('plugin_disabled: Plugin "%s" is not enabled.', $plugin->getCode()));
        }

        $doctor = $this->pluginDoctor->inspect($plugin);
        if (!$doctor->ok()) {
            if (in_array('class_not_found', $doctor->errors, true)) {
                throw new \RuntimeException(sprintf('class_not_found: Plugin payment gateway class "%s" was not found.', $doctor->mainClass));
            }
            if (in_array('interface_not_implemented', $doctor->errors, true)) {
                throw new \RuntimeException(sprintf(
                    'interface_not_implemented: Plugin payment gateway class "%s" does not implement %s.',
                    $doctor->mainClass,
                    PaymentGatewayPluginInterface::class
                ));
            }
            throw new \RuntimeException(sprintf('plugin_error: %s', $doctor->errorMessage()));
        }

        $this->pluginAutoloader->register($plugin);

        $mainClass = trim((string) $plugin->getMainClass());
        if ('' === $mainClass || !class_exists($mainClass)) {
            $this->logger->warning('Plugin payment gateway resolution failed: class_not_found.', [
                'plugin' => $plugin->getCode(),
                'main_class' => $mainClass,
                'expected_file' => $this->pluginAutoloader->expectedFilePathFor($plugin),
            ]);
            throw new \RuntimeException(sprintf('class_not_found: Plugin payment gateway class "%s" was not found.', $mainClass));
        }

        try {
            $instance = new $mainClass();
        } catch (\Throwable $exception) {
            $this->logger->warning('Plugin payment gateway resolution failed: plugin_runtime_error.', [
                'plugin' => $plugin->getCode(),
                'main_class' => $mainClass,
                'error' => $exception->getMessage(),
            ]);
            throw new \RuntimeException(sprintf('plugin_runtime_error: %s', $exception->getMessage()), previous: $exception);
        }

        if (!$instance instanceof PaymentGatewayPluginInterface) {
            $this->logger->warning('Plugin payment gateway resolution failed: interface_not_implemented.', [
                'plugin' => $plugin->getCode(),
                'main_class' => $mainClass,
                'interface' => PaymentGatewayPluginInterface::class,
            ]);
            throw new \RuntimeException(sprintf(
                'interface_not_implemented: Plugin payment gateway class "%s" does not implement %s.',
                $mainClass,
                PaymentGatewayPluginInterface::class
            ));
        }

        return new PluginPaymentGatewayDriverAdapter($plugin, $instance);
    }
}
