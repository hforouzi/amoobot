<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Entity\Plugin;
use App\Payment\Plugin\PaymentGatewayPluginInterface;
use App\Payment\Plugin\PluginConfigSchemaValidator;

final class PaymentPluginDoctor
{
    public function __construct(
        private readonly PluginAutoloader $autoloader,
        private readonly PluginConfigSchemaValidator $schemaValidator,
    ) {
    }

    public function inspect(?Plugin $plugin): PluginDoctorResult
    {
        if (!$plugin instanceof Plugin) {
            return new PluginDoctorResult(false, null, null, '', '', '', '', '', false, PaymentGatewayPluginInterface::class, false, false, [], ['plugin_missing']);
        }

        $manifest = $plugin->getManifest();
        $errors = [];

        $pluginJsonPath = rtrim($this->absolutePluginPath($plugin), \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.'plugin.json';
        if (!is_file($pluginJsonPath)) {
            $errors[] = 'plugin.json missing.';
        }

        if (Plugin::TYPE_PAYMENT_GATEWAY !== $plugin->getType()) {
            $errors[] = 'plugin_type_invalid';
        }
        if (!preg_match('/^[a-z0-9_-]+$/', $plugin->getCode())) {
            $errors[] = 'plugin_code_invalid';
        }

        if (Plugin::STATUS_ERROR === $plugin->getStatus()) {
            $errors[] = 'plugin_error: '.trim((string) $plugin->getErrorMessage());
        }

        if (($manifest['manifestVersion'] ?? null) !== 1) {
            $errors[] = 'manifestVersion must be 1.';
        }

        $mainClass = trim((string) ($manifest['mainClass'] ?? $plugin->getMainClass() ?? ''));
        if ('' === $mainClass) {
            $errors[] = 'mainClass is required.';
        }
        if ($mainClass !== trim((string) ($plugin->getMainClass() ?? ''))) {
            $errors[] = 'plugin mainClass does not match manifest mainClass.';
        }

        $srcDir = $this->autoloader->sourcePathFor($plugin);
        if (!is_dir($srcDir)) {
            $errors[] = 'src directory missing.';
        }

        $namespacePrefix = $this->autoloader->namespaceFor($plugin);
        $classFileCandidate = $this->autoloader->expectedFilePathFor($plugin);
        if ('' !== $mainClass && is_dir($srcDir)) {
            $this->autoloader->register($plugin);
        }

        $classExists = '' !== $mainClass && class_exists($mainClass);
        if (!$classExists) {
            $errors[] = 'class_not_found';
        }

        $implementsInterface = $classExists && is_subclass_of($mainClass, PaymentGatewayPluginInterface::class);
        if ($classExists && !$implementsInterface) {
            $errors[] = 'interface_not_implemented';
        }

        $schemaValidation = $this->schemaValidator->validate($manifest['configSchema'] ?? []);
        if (!$schemaValidation->valid) {
            array_push($errors, ...$schemaValidation->errors);
        }

        return new PluginDoctorResult(
            true,
            $plugin->getStatus(),
            $plugin->getType(),
            $plugin->getPath(),
            $mainClass,
            $namespacePrefix,
            $srcDir,
            $classFileCandidate,
            $classExists,
            PaymentGatewayPluginInterface::class,
            $implementsInterface,
            $schemaValidation->valid,
            $this->schemaValidator->requiredKeys($manifest['configSchema'] ?? []),
            array_values(array_filter($errors, static fn (string $error): bool => '' !== trim($error))),
        );
    }

    private function absolutePluginPath(Plugin $plugin): string
    {
        $srcDir = $this->autoloader->sourcePathFor($plugin);

        return dirname($srcDir);
    }
}
