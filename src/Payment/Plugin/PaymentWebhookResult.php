<?php

declare(strict_types=1);

namespace App\Payment\Plugin;

final readonly class PaymentWebhookResult
{
    public function __construct(
        public bool $handled,
        public ?string $message = null,
        public ?array $payload = null,
    ) {
    }
}
