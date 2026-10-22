<?php

declare(strict_types=1);

namespace App\MiniApp\Checkout;

/**
 * A checkout step the customer cannot take (sales closed, plan gone, invalid input, ...). The error code is stable (the
 * page and the tests read it); the message, when set, comes from a domain service and is already for the customer.
 * Thrown inside CheckoutIdempotency::once(), it rolls the whole call back, so the key can be used again.
 */
final class CheckoutFailure extends \RuntimeException
{
    /**
     * @param array<string, mixed> $extra more data for the page (e.g. the channels to join)
     */
    public function __construct(
        public readonly string $error,
        public readonly int $status,
        public readonly ?string $customerMessage = null,
        public readonly array $extra = [],
    ) {
        parent::__construct($error);
    }
}
