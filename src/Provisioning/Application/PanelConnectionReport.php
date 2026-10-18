<?php

declare(strict_types=1);

namespace App\Provisioning\Application;

use App\Provisioning\Domain\SanaeiIntegrationMode;
use App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http\PanelFailure;

/**
 * Result of a panel connection test, as shown in the admin panel and by app:panel:doctor. Holds no secret: message is
 * redacted, and the token never enters it.
 */
final readonly class PanelConnectionReport
{
    public const TLS_VERIFIED = 'verified';
    public const TLS_PINNED = 'pinned';
    public const TLS_PLAIN_LOCAL = 'plain_local';
    public const TLS_FAILED = 'failed';
    public const TLS_NOT_CHECKED = 'not_checked';

    public function __construct(
        public string $panelUrl,
        /** null = could not tell (the test stopped before) */
        public ?bool $reachable,
        public string $tls,
        public ?bool $authenticated,
        public ?SanaeiIntegrationMode $detectedMode,
        public ?int $latencyMs,
        public ?int $inboundCount,
        public ?PanelFailure $failure,
        /** Technical, redacted detail. */
        public string $message,
        public \DateTimeImmutable $checkedAt,
    ) {
    }

    public function isOk(): bool
    {
        return null === $this->failure && null !== $this->detectedMode;
    }

    /** Translation key (domain admin) of the plain-language explanation and fix: panel_connection.failure.<key>. */
    public function failureKey(): ?string
    {
        return $this->failure?->value;
    }
}
