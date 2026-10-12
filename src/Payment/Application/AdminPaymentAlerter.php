<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Bot\Infrastructure\TelegramApiClient;
use App\Entity\Payment;
use Psr\Log\LoggerInterface;

/**
 * Telegram alerts to the admin chat (TELEGRAM_ADMIN_CHAT_ID) when a payment needs a human.
 *
 * Sent after the settlement transaction has committed; failures are logged, never thrown.
 */
class AdminPaymentAlerter
{
    public function __construct(
        private readonly TelegramApiClient $telegramApiClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $adminChatId = null,
    ) {
    }

    public function needsReview(Payment $payment): void
    {
        $this->send($payment, '⚠️ پرداخت نیازمند بررسی', (string) ($payment->getReviewReason() ?? '-'));
    }

    public function provisioningFailed(Payment $payment): void
    {
        $this->send($payment, '🚨 پرداخت تایید شد اما ساخت سرویس ناموفق بود', (string) ($payment->getProvisioningError() ?? '-'));
    }

    public function overpaid(Payment $payment): void
    {
        $this->send($payment, 'ℹ️ پرداخت بیش از مبلغ سفارش (تایید شد)', sprintf('Verified: %s %s', $payment->getVerifiedAmount() ?? '-', $payment->getVerifiedCurrency() ?? '-'));
    }

    private function send(Payment $payment, string $title, string $detail): void
    {
        $chatId = trim((string) $this->adminChatId);
        if ('' === $chatId) {
            $this->logger->warning('Admin payment alert not sent: TELEGRAM_ADMIN_CHAT_ID is empty.', ['payment_id' => $payment->getId(), 'title' => $title]);

            return;
        }

        $order = $payment->getOrder();
        $text = implode("\n", [
            $title,
            sprintf('Payment ID: %d', $payment->getId() ?? 0),
            sprintf('Order ID: %d (%s)', $order->getId() ?? 0, $order->getType()),
            sprintf('Tracking: %s', $order->getTrackingCode() ?? '-'),
            sprintf('Gateway: %s', $payment->getGatewayType() ?? $payment->getMethod()),
            sprintf('Amount: %d تومان', $payment->getPayableAmount() ?? $payment->getAmount()),
            sprintf('Status: %s', $payment->getStatus()),
            'Detail: '.mb_substr($detail, 0, 1500),
        ]);

        try {
            $this->telegramApiClient->sendMessage($chatId, $text);
        } catch (\Throwable $e) {
            $this->logger->error('Admin payment alert failed.', ['payment_id' => $payment->getId(), 'error' => $e->getMessage()]);
        }
    }
}
