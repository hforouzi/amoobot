<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Probe;

use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;

/**
 * What a capability probe found. detected is null when neither API could be confirmed; failure/message then say why
 * (the token probe's failure, which is the one the operator should fix for a modern panel).
 */
final readonly class CapabilityProbeReport
{
    public function __construct(
        public ?SanaeiIntegrationMode $detected,
        public ?PanelFailure $failure,
        /** Safe (redacted) explanation. */
        public string $message,
        public ?int $latencyMs = null,
        public bool $tokenProbeAttempted = false,
        public bool $legacyProbeAttempted = false,
        public ?int $httpStatus = null,
    ) {
    }

    public function status(): string
    {
        return null !== $this->detected ? 'ok' : ($this->failure->value ?? 'undetected');
    }

    public function isModern(): bool
    {
        return SanaeiIntegrationMode::MODERN === $this->detected;
    }
}
