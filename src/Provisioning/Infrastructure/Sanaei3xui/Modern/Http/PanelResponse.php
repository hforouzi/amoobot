<?php

declare(strict_types=1);

namespace App\Provisioning\Infrastructure\Sanaei3xui\Modern\Http;

/**
 * A successful ({success: true}) panel answer. obj is whatever the panel sent: several endpoints document it only
 * as unknown/null, so callers check its shape (and verify writes with a follow-up GET) instead of trusting it.
 */
final readonly class PanelResponse
{
    public function __construct(
        public mixed $obj,
        public ?string $msg,
        public int $httpStatus,
        public int $latencyMs,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function objArray(): array
    {
        return is_array($this->obj) ? $this->obj : [];
    }
}
