<?php

declare(strict_types=1);

namespace App\Payment\Domain\Webhook;

use App\Entity\PaymentGateway;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gateways that receive callbacks or webhooks.
 *
 * Contract: authenticate the request (signature/secret) where the provider supports it and FAIL
 * CLOSED when the required secret is not configured; return the provider's payment references.
 * Never decide that the payment is paid here.
 */
interface NotificationAwareGatewayInterface
{
    /** Server-to-server notification (IPN/webhook). */
    public const CHANNEL_WEBHOOK = 'webhook';
    /** Customer's browser returning from the payment page. */
    public const CHANNEL_CALLBACK = 'callback';

    public function handleNotification(PaymentGateway $gateway, Request $request, string $channel): GatewayNotification;
}
