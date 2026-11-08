<?php

declare(strict_types=1);

namespace App\Payment\Plugin;

use App\Entity\Payment;

/**
 * Optional for a payment gateway plugin with an invoice lifetime (PaymentGatewayConstraintsInterface): cancel the
 * provider invoice of a row the core closed because a new start replaced it (PaymentStarter, source
 * gateway_invoice_superseded), so the customer cannot pay the old invoice by mistake. See
 * docs/en/plugins/payment-gateway-plugin-sdk.md.
 *
 * Best effort: called after the new invoice was created, outside any lock and transaction. A failure or an exception is
 * logged (the outcome code or the exception's class, never its message) and changes nothing: the row stays closed, and
 * late money on it still reaches settle() and waits in admin review.
 */
interface PaymentGatewayInvoiceCancellationInterface
{
    /**
     * @param array<string, mixed> $config the gateway's config, as for createPayment()
     *
     * @return string a short outcome code without secrets: "cancelled", "skipped" (nothing to cancel) or an error code
     */
    public function cancelInvoice(Payment $payment, array $config): string;
}
