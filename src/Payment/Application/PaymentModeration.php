<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Entity\Payment;
use App\Payment\Application\Settlement\PaymentSettlementService;
use App\Payment\Application\Settlement\SettlementResult;
use App\Payment\Domain\PaymentStatus;
use App\Security\AdminActor;
use App\Security\AdminAuditLogger;

/**
 * An admin's decision on a payment, the same from the web panel, the bot and the Mini App: approve (on a
 * PROVISIONING_FAILED payment that means retry fulfilment, it was already verified), retry, reject (optionally as
 * refunded). The settlement does the work (PaymentSettlementService); every decision is audited with its outcome.
 * Callers only check the permission (PAYMENTS_MODERATE) and present the result.
 */
final class PaymentModeration
{
    /** Where the decision was made: the prefix of the settlement's confirmation source. */
    public const CHANNEL_WEB = 'easyadmin';
    public const CHANNEL_BOT = 'telegram';
    public const CHANNEL_MINIAPP = 'miniapp';

    public function __construct(
        private readonly PaymentSettlementService $settlement,
        private readonly AdminAuditLogger $auditLogger,
    ) {
    }

    /**
     * @param AdminActor|null $actor           null: the logged-in web admin (AdminAuditLogger), else nobody
     * @param string|null     $adminTelegramId the Telegram id the decision came from (bot / Mini App)
     */
    public function approve(Payment $payment, ?AdminActor $actor, ?string $adminTelegramId, string $channel): SettlementResult
    {
        if (PaymentStatus::PROVISIONING_FAILED === $payment->getStatus()) {
            return $this->retry($payment, $actor, $adminTelegramId, $channel);
        }

        $paymentId = $payment->getId();
        $result = $this->settlement->approveByAdmin($payment, $actor?->adminUserId, $adminTelegramId, $channel.'_payment_approval');
        $this->auditLogger->record('payment.approve', 'Payment', $paymentId, ['outcome' => $result->outcome->value], $actor);

        return $result;
    }

    public function retry(Payment $payment, ?AdminActor $actor, ?string $adminTelegramId, string $channel): SettlementResult
    {
        $paymentId = $payment->getId();
        $result = $this->settlement->retryProvisioning($payment, $actor?->adminUserId, $adminTelegramId, $channel.'_retry_provisioning');
        $this->auditLogger->record('payment.retry', 'Payment', $paymentId, ['outcome' => $result->outcome->value], $actor);

        return $result;
    }

    public function reject(Payment $payment, ?string $reason, bool $refunded, ?AdminActor $actor, ?string $adminTelegramId, string $channel): SettlementResult
    {
        $paymentId = $payment->getId();
        $result = $this->settlement->reject($payment, $reason, $actor?->adminUserId, $adminTelegramId, $channel.'_payment_reject', $refunded);
        $this->auditLogger->record('payment.reject', 'Payment', $paymentId, ['outcome' => $result->outcome->value, 'refunded' => $refunded], $actor);

        return $result;
    }
}
