<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Auth;

final readonly class ModernPanelConfigurationResult
{
    private function __construct(
        public ?ModernPanelConfiguration $configuration,
        public ?ModernPanelConfigurationFailure $failure,
        public string $message,
    ) {
    }

    public static function valid(ModernPanelConfiguration $configuration): self
    {
        return new self($configuration, null, 'Modern Sanaei panel configuration is valid.');
    }

    public static function invalid(ModernPanelConfigurationFailure $failure, string $message): self
    {
        return new self(null, $failure, $message);
    }

    public function isValid(): bool
    {
        return null !== $this->configuration;
    }
}
