<?php

declare(strict_types=1);

namespace App\Payment\Plugin;

/**
 * Optional for a payment gateway plugin, on top of PaymentGatewayPluginInterface: what the provider accepts, so the
 * store can hide the method instead of failing after the customer chose it. A plugin without it is usable, accepts any
 * amount and has no invoice lifetime. See docs/en/plugins/payment-gateway-plugin-sdk.md.
 *
 * Fail closed: StorePaymentMethodResolver skips the method when isUsable() or acceptsAmount() throws.
 */
interface PaymentGatewayConstraintsInterface
{
    /**
     * Whether this gateway may be offered with this config (the switch is on, the required values are valid). No network
     * call: it runs for every listing.
     *
     * @param array<string, mixed> $config
     */
    public function isUsable(array $config): bool;

    /** Whether the provider takes this payable amount. The core's StorePaymentMethod min and max apply on top. */
    public function acceptsAmount(int $toman): bool;

    /** How long a provider invoice stays payable, or null when the gateway has no such limit. */
    public function invoiceLifetimeSeconds(): ?int;
}
